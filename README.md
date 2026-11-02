Custom Ontology (module for Omeka S)
====================================

> __New versions of this module and support for Omeka S version 3.0 and above
> are available on [GitLab], which seems to respect users and privacy better
> than the previous repository.__

[Custom Ontology] is a module for [Omeka S] that allows to create specific
classes and properties to describe resources when no standard ontologies are
available, in particular on [LOV], [schema.org], [W3C], and in many other
places. It is useful to manage internal properties, or for researchers who are
creating new data. The properties and classes are available via the standard api
of Omeka S too, like any other ontology.

In fact, this module is a replacement of an existing feature on [Omeka Classic],
where it was allowed to edit and create [item types], equivalent of resource
classes and resource templates, and to create new elements, equivalent of
properties. In Omeka S, in order to share data and to follow the standards of
the semantic web, this feature was not ported.

Note that it is always recommended to search and use existing ontologies first,
because it will be simpler to share data and to link them semantically. If the
properties are known only by you and are not described anywhere, it will be hard
to match them and to make relations with other.

This module avoids to create a specific rdf vocabularies, turtle or n3 files
too, so anybody can use Omeka S like wished, with any documents or resources.


Installation
------------

See general end user documentation for [installing a module].

* From the zip

Download the last release [CustomOntology.zip] from the list of releases and
uncompress it in the `modules` directory.

* From the source and for development

If the module was installed from the source, rename the name of the folder of
the module to `CustomOntology`.

* For test

The module includes a unit, functional and integration test suite. It requires
the module [Common]. Run it from the root of Omeka:

```sh
vendor/bin/phpunit -c modules/CustomOntology/phpunit.xml --testdox
```


Usage
-----

The module adds a button in the header of the vocabulary page and a direct
access in the left sidebar.

Simply click on it and create your ontology, your resources classes or your
properties following the instructions.

The three parts of the form are independent: they can be used together to create
a new ontology with its terms, or separately to add new classes or properties to
an existing ontology.

- The ontology requires a namespace uri, a prefix and a label. The namespace uri
  must end with `/` or `#`. The prefix must be lowercase, start with a letter
  and contain only letters and digits. The prefixes reserved by Omeka (`o`,
  `time`, `cnt`) are not allowed.
- Classes and properties are entered one by row, as `term, label, comment`. The
  comment is optional. The local name of a class starts with an uppercase
  letter (`myprefix:MyClass`), the one of a property with a lowercase letter
  (`myprefix:myProperty`). It may contain letters, digits, `-` and `_`.
- A term can be added only to the new ontology or to an existing one, except the
  default Dublin Core vocabularies, for standardization purpose.
- The existing terms are skipped, so a full list can be pasted again. On the
  contrary, any other error prevents the whole import, so nothing is imported
  partially.

Once the form is filled, import it directly via the `Submit` button, or get the
[turtle] file without saving anything via the button `Get turtle (no save)`.

For example, to create a new ontology for a site available at `https://example.org`,
fill the form with:

```
Namespace uri: https://example.org/ns/demo/
Prefix: demo
Label: Demonstration vocabulary
Comment: A sample vocabulary.
```

Classes:

```
demo:Book, Book, A published or written work.
demo:Article, Article, A written piece published in a larger work.
```

Properties:

```
demo:author, Author, The person or the organization responsible for the work.
demo:pageCount, Page count
```

You can add new classes and properties into previously imported ontologies,
but you cannot update existing ones from the form. Nevertheless, you still can
use the turtle file to upgrade the vocabulary later, like for any other
vocabularies, at the core admin page `admin/vocabulary`. Note that an update via
a turtle file adds new terms and updates labels and comments, but it never
removes nor renames an existing term.

The unused terms of the custom ontologies can be deleted from the page of the
vocabularies, via the icon "Delete unused terms". A term is used when it is set
in a resource (class or value), in a resource template, in a setting (global,
site or user), in a site (navigation, item pool and page blocks) or in a search
config (modules [Advanced Search] and [Search Solr]), so it cannot be deleted.
The term is searched by name ("prefix:localName") and by id in the queries and
in the json data ("property[0][property]=12", "resource_class_id[]=5") or as
value of a setting whose name contains "property". The terms of the vocabularies
that are not managed by the module cannot be deleted.

When the custom ontologies are named as proposed (Omeka url + `/ns/prefix`),
they will be publicly listed at `https://example.org/ns` and available as
turtle and other rdf formats (json-ld, rdf/xml, ntriples, [Notation3]), at their
namespace uri, and as a web page at `https://example.org/ns/{prefix}?format=html`.
Content negotiation is managed anyway, so the content type accepted in the
request will be used if not specified by the url query.


TODO
----

- [ ] Add support of version in namespace url.
- [ ] Add edition of terms and labels directly on the page vocabulary, with a
  main edit button and some js (see module Folksonomy).


Warning
-------

Use it at your own risk.

It’s always recommended to backup your files and your databases and to check
your archives regularly so you can roll back if needed.


Troubleshooting
---------------

See online issues on the [module issues] page on GitLab.


License
-------

This module is published under the [CeCILL v2.1] license, compatible with
[GNU/GPL] and approved by [FSF] and [OSI].

In consideration of access to the source code and the rights to copy, modify and
redistribute granted by the license, users are provided only with a limited
warranty and the software’s author, the holder of the economic rights, and the
successive licensors only have limited liability.

In this respect, the risks associated with loading, using, modifying and/or
developing or reproducing the software by the user are brought to the user’s
attention, given its Free Software status, which may make it complicated to use,
with the result that its use is reserved for developers and experienced
professionals having in-depth computer knowledge. Users are therefore encouraged
to load and test the suitability of the software as regards their requirements
in conditions enabling the security of their systems and/or data to be ensured
and, more generally, to use and operate it in the same conditions of security.
This Agreement may be freely reproduced and published, provided it is not
altered, and that no provisions are either added or removed herefrom.


Copyright
---------

* Copyright Daniel Berthereau, 2018-2026 (see [Daniel-KM] on GitLab)


[Omeka S]: https://omeka.org/s
[Custom Ontology]: https://gitlab.com/Daniel-KM/Omeka-S-module-CustomOntology
[LOV]: https://lov.okfn.org
[schema.org]: https://schema.org
[W3C]: https://w3c.org
[Omeka Classic]: https://omeka.org/classic
[item types]: https://omeka.org/classic/docs/Content/Item_Types
[installing a module]: https://omeka.org/s/docs/user-manual/modules/#installing-modules
[CustomOntology.zip]: https://gitlab.com/Daniel-KM/Omeka-S-module-CustomOntology/-/releases
[Common]: https://gitlab.com/Daniel-KM/Omeka-S-module-Common
[Advanced Search]: https://gitlab.com/Daniel-KM/Omeka-S-module-AdvancedSearch
[Search Solr]: https://gitlab.com/Daniel-KM/Omeka-S-module-SearchSolr
[turtle]: https://wikipedia.org/wiki/Turtle_(syntax)
[Notation3]: https://wikipedia.org/wiki/Notation3
[module issues]: https://gitlab.com/Daniel-KM/Omeka-S-module-CustomOntology/-/work_items
[CeCILL v2.1]: https://www.cecill.info/licences/Licence_CeCILL_V2.1-en.html
[GNU/GPL]: https://www.gnu.org/licenses/gpl-3.0.html
[FSF]: https://www.fsf.org
[OSI]: http://opensource.org
[GitLab]: https://gitlab.com/Daniel-KM
[Daniel-KM]: https://gitlab.com/Daniel-KM "Daniel Berthereau"
