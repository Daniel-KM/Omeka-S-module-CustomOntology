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

Uncompress files and rename module folder `CustomOntology`. Then install it like
any other Omeka module and follow the config instructions.

See general end user documentation for [Installing a module].


Usage
-----

Simply click on the menu `Custom Ontology` and create your ontology, your
resources classes and/or your properties following the instructions.

Once the form is filled, just import it directly via the `Submit` button.

You can add new classes and properties into previously imported ontologies,
but you cannot update existing ones from the form. Nevertheless, you still can
use the turtle file to upgrade the vocabulary later, like for any other
vocabularies, at the core admin page `admin/vocabulary`.

When the custom ontologies are named as proposed (Omeka url + `/ns/prefix`),
they will be publicly listed at `https://example.org/ns` and available as
turtle, (a common simplified [Notation3] format), at their namespace uri, and as
a web page at `https://example.org/ns/{prefix}?format=html`.


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

* Copyright Daniel Berthereau, 2018-2021 (see [Daniel-KM] on GitLab)


[Omeka S]: https://omeka.org/s
[Custom Ontology]: https://gitlab.com/Daniel-KM/Omeka-S-module-CustomOntology
[LOV]: https://lov.okfn.org
[schema.org]: https://schema.org
[W3C]: https://w3c.org
[Omeka Classic]: https://omeka.org/classic
[item types]: https://omeka.org/classic/docs/Content/Item_Types
[Installing a module]: https://omeka.org/s/docs/user-manual/modules/#installing-modules
[turtle]: https://wikipedia.org/wiki/Turtle_(syntax)
[Notation3]: https://wikipedia.org/wiki/Notation3
[module issues]: https://gitlab.com/Daniel-KM/Omeka-S-module-CustomOntology/-/issues
[CeCILL v2.1]: https://www.cecill.info/licences/Licence_CeCILL_V2.1-en.html
[GNU/GPL]: https://www.gnu.org/licenses/gpl-3.0.html
[FSF]: https://www.fsf.org
[OSI]: http://opensource.org
[GitLab]: https://gitlab.com/Daniel-KM
[Daniel-KM]: https://gitlab.com/Daniel-KM "Daniel Berthereau"
