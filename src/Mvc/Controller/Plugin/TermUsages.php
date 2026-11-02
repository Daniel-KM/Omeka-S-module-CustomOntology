<?php declare(strict_types=1);

namespace CustomOntology\Mvc\Controller\Plugin;

use Doctrine\DBAL\Connection;
use Laminas\Mvc\Controller\Plugin\AbstractPlugin;

/**
 * List the usages of the terms of a vocabulary.
 *
 * A term is used when it is set in a resource, in a resource template, in the
 * settings or in the search configs (modules Advanced Search and Search Solr).
 * The database is queried directly, so the private resources are counted too.
 */
class TermUsages extends AbstractPlugin
{
    /**
     * Text columns where a term may be stored, by table.
     *
     * The label is a sql expression on the table aliased "t", with optional
     * joins. The key is the name of a setting: when it refers to a property,
     * the value may be a single id or a list of ids. The tables of optional
     * modules are skipped when missing.
     *
     * @var array
     */
    protected $textSources = [
        'setting' => [
            'group' => 'settings',
            'label' => 't.id',
            'key' => 't.id',
            'columns' => ['value'],
        ],
        'site_setting' => [
            'group' => 'settings',
            'label' => "CONCAT(s.slug, '/', t.id)",
            'key' => 't.id',
            'join' => 'JOIN site s ON s.id = t.site_id',
            'columns' => ['value'],
        ],
        'user_setting' => [
            'group' => 'settings',
            'label' => "CONCAT(t.user_id, '/', t.id)",
            'key' => 't.id',
            'columns' => ['value'],
        ],
        'site' => [
            'group' => 'sites',
            'label' => 't.slug',
            'columns' => ['navigation', 'item_pool'],
        ],
        'site_page_block' => [
            'group' => 'sites',
            'label' => "CONCAT(s.slug, '/', p.slug, '#', t.id)",
            'join' => 'JOIN site_page p ON p.id = t.page_id JOIN site s ON s.id = p.site_id',
            'columns' => ['data', 'layout_data'],
        ],
        'search_config' => [
            'group' => 'search',
            'label' => 't.name',
            'columns' => ['settings'],
        ],
        'search_engine' => [
            'group' => 'search',
            'label' => 't.name',
            'columns' => ['settings'],
        ],
        'search_suggester' => [
            'group' => 'search',
            'label' => 't.name',
            'columns' => ['settings'],
        ],
        'solr_core' => [
            'group' => 'search',
            'label' => 't.name',
            'columns' => ['settings'],
        ],
        'solr_map' => [
            'group' => 'search',
            'label' => 't.source',
            'columns' => ['source', 'pool', 'settings'],
        ],
    ];

    /**
     * Keys used to set a term by id in queries and configs, by type.
     *
     * @var array
     */
    protected $idKeys = [
        'properties' => ['property', 'property_id'],
        'resource_classes' => ['resource_class', 'resource_class_id'],
    ];

    /**
     * Part of the name of a setting whose value may be ids, by type.
     *
     * The resource classes are skipped: "class" is too common in settings.
     *
     * @var array
     */
    protected $settingNames = [
        'properties' => 'propert',
    ];

    /**
     * @var Connection
     */
    protected $connection;

    /**
     * @var string[]|null
     */
    protected $tables;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    /**
     * Get the usages of terms of a vocabulary.
     *
     * @param string $type "properties" or "resource_classes".
     * @param array $terms Terms ("prefix:localName") by id.
     * @return array Usages by term id, with keys "resources" (count),
     *   "templates" (count), "settings", "sites" and "search" (list of places).
     */
    public function __invoke(string $type, array $terms): array
    {
        $usages = [];
        foreach (array_keys($terms) as $id) {
            $usages[$id] = ['resources' => 0, 'templates' => 0, 'settings' => [], 'sites' => [], 'search' => []];
        }
        if (!$terms) {
            return $usages;
        }

        $ids = array_map('intval', array_keys($terms));
        foreach ($this->countResources($type, $ids) as $id => $count) {
            $usages[$id]['resources'] = $count;
        }
        foreach ($this->countTemplates($type, $ids) as $id => $count) {
            $usages[$id]['templates'] = $count;
        }
        foreach ($this->findInTexts($type, $terms) as $id => $places) {
            foreach ($places as $group => $list) {
                $usages[$id][$group] = $list;
            }
        }
        return $usages;
    }

    /**
     * Check if a list of usages contains at least one usage.
     */
    public function isUsed(array $usage): bool
    {
        return $usage['resources'] || $usage['templates'] || $usage['settings'] || $usage['sites'] || $usage['search'];
    }

    /**
     * Count the values (properties) or the resources (classes).
     */
    protected function countResources(string $type, array $ids): array
    {
        $sql = $type === 'properties'
            ? 'SELECT property_id, COUNT(*) FROM value WHERE property_id IN (:ids) GROUP BY property_id'
            : 'SELECT resource_class_id, COUNT(*) FROM resource WHERE resource_class_id IN (:ids) GROUP BY resource_class_id';
        return array_map('intval', $this->connection->fetchAllKeyValue(
            $sql, ['ids' => $ids], ['ids' => Connection::PARAM_INT_ARRAY]
        ));
    }

    /**
     * Count the resource templates that use the terms.
     */
    protected function countTemplates(string $type, array $ids): array
    {
        $sqls = $type === 'properties'
            ? [
                'SELECT property_id, resource_template_id FROM resource_template_property WHERE property_id IN (:ids)',
                'SELECT title_property_id, id FROM resource_template WHERE title_property_id IN (:ids)',
                'SELECT description_property_id, id FROM resource_template WHERE description_property_id IN (:ids)',
            ]
            : [
                'SELECT resource_class_id, id FROM resource_template WHERE resource_class_id IN (:ids)',
            ];
        $templates = [];
        foreach ($sqls as $sql) {
            $rows = $this->connection->fetchAllNumeric($sql, ['ids' => $ids], ['ids' => Connection::PARAM_INT_ARRAY]);
            foreach ($rows as [$termId, $templateId]) {
                $templates[$termId][$templateId] = true;
            }
        }
        return array_map('count', $templates);
    }

    /**
     * Find the terms in the settings, the sites and the search configs.
     *
     * A term may be set as a string ("prefix:localName") or by id, like in the
     * queries ("property[0][property]=12", "resource_class_id[]=5") or in json.
     *
     * @return array Places by group by term id.
     */
    protected function findInTexts(string $type, array $terms): array
    {
        // All the terms of a vocabulary share the prefix, so the candidate rows
        // contain it, encoded or not, or the key used for the ids.
        $prefix = addcslashes(strtok((string) reset($terms), ':'), '%_\\');
        $likes = [
            '%' . $prefix . ':%',
            '%' . $prefix . '\%3A%',
            '%' . reset($this->idKeys[$type]) . '%',
        ];

        $result = [];
        foreach ($this->textSources as $table => $source) {
            if (!$this->tableExists($table)) {
                continue;
            }
            foreach ($source['columns'] as $column) {
                $where = implode(' OR ', array_fill(0, count($likes), "t.`$column` LIKE ?"));
                $params = $likes;
                if (isset($source['key'], $this->settingNames[$type])) {
                    $where .= " OR {$source['key']} LIKE ?";
                    $params[] = '%' . $this->settingNames[$type] . '%';
                }
                $rows = $this->connection->fetchAllNumeric(
                    "SELECT {$source['label']}, t.`$column`, " . ($source['key'] ?? 'NULL') . " FROM `$table` t " . ($source['join'] ?? '') . " WHERE $where",
                    $params
                );
                foreach ($rows as [$label, $text, $key]) {
                    // Facets and filters may be set in encoded queries.
                    $raw = (string) $text;
                    $text = $raw . "\n" . urldecode($raw);
                    $place = $table . ' ' . $label;
                    $ids = $this->findIds($text, $this->idKeys[$type]);
                    if ($key !== null
                        && isset($this->settingNames[$type])
                        && strpos((string) $key, $this->settingNames[$type]) !== false
                    ) {
                        $ids += $this->settingIds($raw);
                    }
                    foreach ($terms as $id => $term) {
                        if (isset($ids[$id]) || $this->containsTerm($text, $term)) {
                            $result[$id][$source['group']][$place] = $place;
                        }
                    }
                }
            }
        }

        foreach ($result as &$groups) {
            $groups = array_map('array_values', $groups);
        }
        unset($groups);
        return $result;
    }

    /**
     * Check if a text contains a term as a whole, not as a part of another one.
     *
     * So "demo:author" is not found in "demo:authorName", but it is found in a
     * json string or in a path like "item_set/demo:author".
     */
    protected function containsTerm(string $text, string $term): bool
    {
        return (bool) preg_match('~(?<![\w:.-])' . preg_quote($term, '~') . '(?![\w-])~u', $text);
    }

    /**
     * Get the ids set with the specified keys in a query or in a json string.
     *
     * The query may be url-encoded, and the value may be a single id or a list
     * of ids: "[property]=12", "resource_class_id[]=5", '"property":"12"',
     * '"resource_class_id":["5","7"]'.
     *
     * @return array Ids as keys.
     */
    protected function findIds(string $text, array $keys): array
    {
        $regex = '~(?<![\w-])(?:' . implode('|', $keys) . ')(?:\]|")?(?:\[\d*\])*\s*[=:]\s*(\[?[\d",\s]*\d)~';
        if (!preg_match_all($regex, urldecode($text), $matches)) {
            return [];
        }
        preg_match_all('~\d+~', implode(' ', $matches[1]), $ids);
        return array_fill_keys(array_map('intval', $ids[0]), true);
    }

    /**
     * Get the ids stored as value of a setting: a single id or a list of ids.
     *
     * @return array Ids as keys.
     */
    protected function settingIds(string $text): array
    {
        $value = json_decode($text, true);
        $values = is_array($value) ? $value : [$value];
        $ids = [];
        foreach ($values as $value) {
            if (is_int($value) || (is_string($value) && ctype_digit($value))) {
                $ids[(int) $value] = true;
            }
        }
        return $ids;
    }

    protected function tableExists(string $table): bool
    {
        if ($this->tables === null) {
            // The method createSchemaManager() does not exist in dbal 2.
            $schemaManager = method_exists($this->connection, 'createSchemaManager')
                ? $this->connection->createSchemaManager()
                : $this->connection->getSchemaManager();
            $this->tables = $schemaManager->listTableNames();
        }
        return in_array($table, $this->tables, true);
    }
}
