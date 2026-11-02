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
     * Text columns where a term may be stored as a string, by table.
     *
     * The tables of optional modules are skipped when missing.
     *
     * @var array
     */
    protected $textSources = [
        'setting' => [
            'group' => 'settings',
            'label' => ['id'],
            'columns' => ['value'],
        ],
        'site_setting' => [
            'group' => 'settings',
            'label' => ['site_id', 'id'],
            'columns' => ['value'],
        ],
        'search_config' => [
            'group' => 'search',
            'label' => ['name'],
            'columns' => ['settings'],
        ],
        'search_engine' => [
            'group' => 'search',
            'label' => ['name'],
            'columns' => ['settings'],
        ],
        'search_suggester' => [
            'group' => 'search',
            'label' => ['name'],
            'columns' => ['settings'],
        ],
        'solr_core' => [
            'group' => 'search',
            'label' => ['name'],
            'columns' => ['settings'],
        ],
        'solr_map' => [
            'group' => 'search',
            'label' => ['source'],
            'columns' => ['source', 'pool', 'settings'],
        ],
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
     *   "templates" (count), "settings" and "search" (list of places).
     */
    public function __invoke(string $type, array $terms): array
    {
        $usages = [];
        foreach (array_keys($terms) as $id) {
            $usages[$id] = ['resources' => 0, 'templates' => 0, 'settings' => [], 'search' => []];
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
        foreach ($this->findInTexts($terms) as $id => $places) {
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
        return $usage['resources'] || $usage['templates'] || $usage['settings'] || $usage['search'];
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
     * Find the terms written as strings in the settings and search configs.
     *
     * @return array Places by group ("settings" or "search") by term id.
     */
    protected function findInTexts(array $terms): array
    {
        // All the terms of a vocabulary share the prefix, so a single query by
        // column is enough to get the candidate rows.
        $prefixes = [];
        foreach ($terms as $term) {
            $prefixes[strtok($term, ':')] = true;
        }

        $result = [];
        foreach ($this->textSources as $table => $source) {
            if (!$this->tableExists($table)) {
                continue;
            }
            $labelColumns = implode(', ', array_map(fn ($c) => "`$c`", $source['label']));
            foreach ($source['columns'] as $column) {
                foreach (array_keys($prefixes) as $prefix) {
                    $rows = $this->connection->fetchAllAssociative(
                        "SELECT $labelColumns, `$column` AS `_text` FROM `$table` WHERE `$column` LIKE :prefix",
                        ['prefix' => '%' . addcslashes($prefix, '%_\\') . ':%']
                    );
                    foreach ($rows as $row) {
                        $text = (string) $row['_text'];
                        unset($row['_text']);
                        $place = $table . ' ' . implode('/', $row);
                        foreach ($terms as $id => $term) {
                            if ($this->containsTerm($text, $term)) {
                                $result[$id][$source['group']][$place] = $place;
                            }
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
