﻿===========
Persistence
===========

.. sectionauthor:: Karsten Dambekalns <karsten@typo3.org>

This chapter explains how to use object persistence in FLOW3. To do this, it focuses on
the persistence based on the *Doctrine* 2 ORM first. There is another mechanism available,
called *Generic* persistence, which also can be used to add your own persistence backends
to FLOW3. It is explained seperately later in the chapter.

.. tip::

	If you have experience with Doctrine 2 already, your knowledge can
	be applied fully in FLOW3. If you have not worked with Doctrine 2 in the
	past, it might be helpful to learn more about it, as that might clear up
	questions this documentation might leave open.

Introductory Example
====================

Let's look at the following example as an introduction to how FLOW3 handles persistence.
We have a domain model of a Blog, consisting of Blog, Post, Comment and Tag objects:

.. figure:: /Images/TheDefinitiveGuide/PartIII/Persistence_BlogDomainModel.png
	:align: center
	:width: 400pt
	:alt: The objects of the Blog domain model

	The objects of the Blog domain model

Connections between those objects are built (mostly) by simple references in PHP, as a
look at the ``addPost()`` method of the ``Blog`` class shows:

*Example: The Blog's addPost() method* ::

	/**
	 * @param \TYPO3\Blog\Domain\Model\Post $post
	 * @return void
	 */
	public function addPost(\TYPO3\Blog\Domain\Model\Post $post) {
	    $post->setBlog($this);
	    $this->posts->add($post);
	}

The same principles are applied to the rest of the classes, resulting in an object tree of
a blog object holding several posts, those in turn having references to their associated
comments and tags.

But now we need to make sure the ``Blog`` and the data in it are still available the next
time we need them. In the good old days of programming you might have
added some ugly database calls all over the system at this point. In the currently
widespread practice of loving Active Record you'd still add ``save()`` methods to all or most
of your objects. But can it be even easier?

To access an object you need to hold some reference to it. You can get that reference by
creating an object or by following some reference to it from some object you already have.
This leaves you at a point where you need to find that "first object". This is done by
using a *Repository*. A Repository is the librarian of your system, knowing about all the
objects it manages. In our model the ``Blog`` is the entry point to our object tree,
so we will add a ``BlogRepository``, allowing us to find ``Blog`` instances by the criteria we need.

Now, before we can find a ``Blog``, we need to create and save one. What we do is create the
object and ``add`` it to the ``BlogRepository``. This will automagically persist your Blog
and you can retrieve it again later.

For all that magic to work as expected, you need to give some hints. This doesn't mean you
need to write tons of XML, a few annotations in your code are enough:

*Example: Persistence-related annotations in the Blog class* ::

	namespace TYPO3\Blog\Domain\Model;

	/**
	 * A Blog object
	 *
	 * @FLOW3\Entity
	 */
	class Blog {

	    /**
	     * @var string
	     * @FLOW3\Validate(type="Text")
	     * @FLOW3\Validate(type="StringLength", options={ "minimum"=1, "maximum"=80 })
	     * @ORM\Column(length=80)
	     */
	    protected $title;

	    /**
	     * @var \Doctrine\Common\Collections\ArrayCollection<\TYPO3\Blog\Domain\Model\Post>
	     * @ORM\OneToMany(mappedBy="blog")
	     * @ORM\OrderBy({"date" = "DESC"})
	     */
	    protected $posts;

	    ...

	}

The first annotation to note is the ``Entity`` annotation, which tells the persistence
framework it needs to persist ``Blog`` instances if they have been added to a Repository. In
the ``Blog`` class we have some member variables, they are persisted as well by default. The
persistence framework knows their types by looking at the ``@var``  annotation you use anyway
when documenting your code (you do document your code, right?).

The *Column* annotation on ``$title`` is an optimization since we allow only 80 chars
anyway. In case of the ``$posts`` property the persistence framework persists the objects held
in that ``ArrayCollection`` as independent objects in a one-to-many relationship. Apart from those
annotations your domain object's code is completely unaware of the persistence infrastructure.

Let's conclude by taking a look at the BlogRepository code:

*Example: Code of a simple BlogRepository* ::

	/**
	 * A BlogRepository
	 *
	 * @FLOW3\Scope("singleton")
	 */
	class BlogRepository extends \TYPO3\FLOW3\Persistence\Repository {
	}

As you can see we get away with very little code by simply extending the FLOW3-provided
repository class, and still we already have methods like ``findAll()`` and even magic
calls like ``findOneBy<PropertyName>()`` available. If we need some specialized find
methods in our repository, we can make use of the query building API:

*Example: Using the query building API in a Repository* ::

	/**
	 * A PostRepository
	 */
	class PostRepository extends \TYPO3\FLOW3\Persistence\Repository {

	    /**
	     * Finds posts by the specified tag and blog
	     *
	     * @param \TYPO3\Blog\Domain\Model\Tag $tag
	     * @param \TYPO3\Blog\Domain\Model\Blog $blog The blog the post must refer to
	     * @return \TYPO3\FLOW3\Persistence\QueryResultInterface The posts
	     */
	    public function findByTagAndBlog(\TYPO3\Blog\Domain\Model\Tag $tag,
	      \TYPO3\Blog\Domain\Model\Blog $blog) {
	        $query = $this->createQuery();
	        return $query->matching(
	            $query->logicalAnd(
	                $query->equals('blog', $blog),
	                $query->contains('tags', $tag)
	            )
	        )
	        ->setOrderings(array(
	            'date' => \TYPO3\FLOW3\Persistence\QueryInterface::ORDER_DESCENDING)
	        )
	        ->execute();
	    }
	}

If you like to do things the hard way you can get away with implementing
``\TYPO3\FLOW3\Persistence\RepositoryInterface`` yourself, though that is
something the normal developer never has to do.

Basics of Persistence in FLOW3
==============================

On the Principles of DDD
------------------------

From Evans, the rules we need to enforce include:

* The root Entity has global identity and is ultimately responsible for checking
  invariants.
* Root Entities have global identity. Entities inside the boundary have local identity,
  unique only within the Aggregate.
* Nothing outside the Aggregate boundary can hold a reference to anything inside, except
  to the root Entity. The root Entity can hand references to the internal Entities to
  other objects, but they can only use them transiently (within a single method or
  block).
* Only Aggregate Roots can be obtained directly with database queries. Everything else
  must be done through traversal.
* Objects within the Aggregate can hold references to other Aggregate roots.
* A delete operation must remove everything within the Aggregate boundary all at once.
* When a change to any object within the Aggregate boundary is committed, all invariants
  of the whole Aggregate must be satisfied.

On the relationship between adding and retrieving
-------------------------------------------------

When you ``add()`` something to a repository and do a ``findAll()`` immediately
afterwards, you might be surprised: the freshly added object will not be found. This is
not a bug, but a decision we took on purpose. Here is why.

When you add an object to a repository, it is added to the internal identity map and will
be persisted later (when ``persistAll()`` is called). It is therefore still in a transient
state - but all query operations go directly to the underlying data storage, because we
need to check that anyway. So instead of trying to query the in-memory objects we decided
to ignore transient objects for queries [#]_.

If you need to query for objects you just created, feel free to have the
``PersistenceManager`` injected and use ``persistAll()`` in your code.

How changes are persisted
-------------------------

When you ``add`` or ``remove`` an object to or from a repository, the object will be added to
or removed from the underlying persistence as expected upon ``persistAll``. But what about
changes to already persisted objects? As we have seen, those changes are only persisted, if
the changed object is given to ``update`` on the corresponding repository.

Now, for objects that have no corresponding repository, how are changes persisted? In the
same way you fetch those objets from their parent - by traversal. FLOW3 follows references
from objects managed in a repository (aggregate roots) for all persistence operations,
unless the referenced object itself is an aggregate root.

When using the Doctrine 2 persistence, this is done by virtually creating cascade attributes
on the mapped associations.

Conventions for File and Class Names
====================================

To allow FLOW3 to detect the object type a repository is responsible for, certain
conventions need to be followed:

* Domain models should reside in a *Domain/Model* directory
* Repositories should reside in a *Domain/Repository* directory and be named
  ``<ModelName>Repository``
* Aside from ``Model`` versus ``Repository`` the qualified class class names should be the
  same for corresponding classes
* Repositories must implement ``\TYPO3\FLOW3\Persistence\RepositoryInterface`` (which is
  the case when extending ``\TYPO3\FLOW3\Persistence\Repository``)

*Example: Conventions for model and repository naming*

.. code-block:: text

	\TYPO3
	  \Blog
	    \Domain
	      \Model
	        Blog
	        Post
	      \Repository
	        BlogRepository
	        PostRepository

Another way to bind a repository to a model is to define a class constant named
``ENTITY_CLASSNAME`` in your repository and give it the desired model name as value. This
should be done only when following the conventions outlined above is not feasible.

Lazy Loading
============

Lazy Loading is a feature that can be equally helpful and dangerous when it comes to
optimizing your application. FLOW3 defaults to lazy loading when using Doctrine, i.e. it
loads all the data in an object as soon as you fetch the object from the persistence layer
but does not fetch data of associated objects. This avoids massive amounts of objects
being reconstituted if you have a large object tree. Instead it defers property thawing in
objects until the point when those properties are really needed.

The drawback of this: If you access associated objects, each access will fire a request to
the persistent storage now. So there might be situations when eager loading comes in
handy to avoid excessive database roundtrips. Eager loading is the default when using the
*Generic* persistence mechanism and can be achieved for the Doctrine 2 ORM by using join
operations  in DQL or specifying the fetch mode in the mapping configuration.

Doctrine Persistence
======================

Doctrine 2 ORM is used by default in FLOW3. Aside from very few internal changes it
consists of the regular Doctrine ORM, DBAL, Migrations and Common libraries and is tied
into FLOW3 by some glue code and (most important) a custom annotation driver for metadata
consumption.

Requirements and restrictions
-----------------------------

There are some rules imposed by Doctrine (and/or FLOW3) you need to follow for your
entities (and value objects). Most of them are good practice anyway, and thus are not
really restrictions.

* Entity classes must not be ``final`` or contain ``final`` methods.
* Persistent properties of any entity class should always be ``protected``, not ``public``,
  otherwise lazy-loading might not work as expected.
* Implementing ``__clone()`` or ``__wakeup()`` is not a problem with FLOW3, as the
  instances always have an identity. If using your own identity properties, you must
  wrap any code you intend to run in those methods in an identity check.
* Entity classes in a class hierarchy that inherit directly or indirectly from one another
  must not have a mapped property with the same name.
* Entities cannot use ``func_get_args()`` to implement variable parameters. The proxies
  generated by Doctrine do not support this for performance reasons and your code might
  actually fail to work when violating this restriction.

Persisted instance variables must be accessed only from within the entity instance itself,
not by clients of the entity. The state of the entity should be available to clients only through
the entity’s methods, i.e. getter/setter methods or other business methods.

Collection-valued persistent fields and properties must be defined in terms of the
``Doctrine\Common\Collections\Collection`` interface. The collection implementation type
may be used by the application to initialize fields or properties before the entity is
made persistent. Once the entity becomes managed (or detached), subsequent access must
happen through the interface type.

Metadata mapping
----------------

The Doctrine 2 ORM needs to know a lot about your code to be able to persist it. Natively
Doctrine 2 supports the use of annotations, XML, YAML and PHP to supply that information.
In FLOW3, only annotations are supported, as this aligns with the philosophy behind the
framework.

Annotations for the Doctrine Persistence
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

The following table lists the most common annotations used by the persistence framework
with their name, scope and meaning:

:title:`Persistence-related code annotations`

+------------------+----------+----------------------------------------------------------+
+ Annotation       + Scope    + Meaning                                                  +
+==================+==========+==========================================================+
+ ``Entity``       + Class    + Declares a class as an Entity.                           +
+------------------+----------+----------------------------------------------------------+
+ ``ValueObject``  + Class    + Declares a class as a Value Object, allowing the         +
+                  +          + persistence framework to reuse an existing object if one +
+                  +          + exists. *Doctrine 2 does not (yet) support value         +
+                  +          + objects, thus we handle this like an entity for the time +
+                  +          + being.*                                                  +
+------------------+----------+----------------------------------------------------------+
+ ``Column``       + Variable + Allows to take influence on the column actually          +
+                  +          + generated for this property in the database.             +
+                  +          + Particularly useful with string properties to limit the  +
+                  +          + space used or to enable storage of more than 255         +
+                  +          + characters.                                              +
+------------------+----------+----------------------------------------------------------+
+ ``ManyToOne``,   + Variable + Defines the type of object associations, refer to the    +
+ ``OneToMany``,   +          + Doctrine 2 documentation for details. The most obvious   +
+ ``ManyToMany``,  +          + difference to plain Doctrine 2 is that the               +
+ ``OneToOne``     +          + ``targetEntity`` parameter can be omitted, it is taken   +
+                  +          + from the ``@var`` annotation.                            +
+                  +          +                                                          +
+                  +          + The ``cascade`` attribute is set to cascade all          +
+                  +          + operations on associations within aggregate boundaries.  +
+                  +          + In that case orphanRemoval is turned on as well.         +
+------------------+----------+----------------------------------------------------------+
+ ``@var``         + Variable + Is used to detect the type a variable has. For           +
+                  +          + collections, the type is given in angle brackets.        +
+------------------+----------+----------------------------------------------------------+
+ ``Transient``    + Variable + Makes the persistence framework ignore the variable.     +
+                  +          + Neither will it's value be persisted, nor will it be     +
+                  +          + touched during reconstitution.                           +
+------------------+----------+----------------------------------------------------------+
+ ``Identity``     + Variable + Marks the variable as being relevant for determining     +
+                  +          + the identity of an object in the domain.                 +
+------------------+----------+----------------------------------------------------------+

Doctrine supports many more annotations, for a full reference please consult the Doctrine
2 ORM documentation.

Differences between FLOW3 and plain Doctrine
--------------------------------------------

The custom annotation driver used by FLOW3 to collect mapping information from the code
makes a number of things easier, compared to plain Doctrine 2.

* ``Entity``

  * ``repositoryClass`` can be left out, if you follow the naming rules for your
    repository classes explained above.

* ``Table``

  * ``name`` does not default to the unqualified entity classname, but a name is generated
    from classname, package key and more elements to make it unique.

* ``Id``

  * Can be left out, as it is automatically generated, this means you also do not need
    ``@GeneratedValue``. Every entity will get a property injected that is filled with
    an UUID upon instantiation and used as technical identifier.
  * If an ``@Id`` annotation is found, it is of course used as is and no magic will happen.

* ``Column``

  * Can usually be left out altogether, as the vital *type* information can be read from
    the ``@var`` annotation on a class member.

    .. important::
      Since PHP does not differentiate between short and long strings, but databases do,
      you must use ``@Column(type="text")`` if you intend to store more than 255
      characters in a string property.

* ``OneToOne``
* ``OneToMany``
* ``ManyToOne``
* ``ManyToMany``

  * ``targetEntity`` can be omitted, it is read from the ``@var`` annotation on the property

* ``JoinTable``
* ``JoinColumn``

  * Can usually be left out completely, the needed information is gathered automatically
  * But *when using a self-referencing association*, you will need to help FLOW3 a
    little, so it doesn't generate a join table with only one column.

    *Example: JoinTable annotation for a self-referencing annotation*::

		/**
		 * @var \Doctrine\Common\Collections\ArrayCollection<\TYPO3\Blog\Domain\Model\Post>
		 * @ORM\ManyToMany
		 * @ORM\JoinTable(inverseJoinColumns={@ORM\JoinColumn(name="related_id")})
		 */
		 protected $relatedPosts;

	Without this, the created table would not  contain two columns but only one, named
	after the identifiers of the associated entities - which is the same in this case.

* ``DiscriminatorColumn``
* ``DiscriminatorMap``

  * Can be left out, as they are automatically generated.

The generation of this metadata is slightly more expensive compared to the plain Doctrine
``AnnotationDriver``, but since this information can be cached after being generated once,
we feel the gain when developing outweighs this easily.

.. tip::

	Anything you explicitly specify in annotations regarding Doctrine, has precedence over
	the automatically generated metadata. This can be used to fully customize the mapping
	of database tables to models.

Here is an example to illustrate the things you can omit, due to the automatisms in the
FLOW3 annotation driver.

*Example: Annotation equivalents in FLOW3 and plain Doctrine 2*

An entity with only the annotations needed in FLOW3::

	/**
	 * @FLOW3\Entity
	 */
	class Post {

	  /**
	   * @var \TYPO3\Blog\Domain\Model\Blog
	   * @ORM\ManyToOne(inversedBy="posts")
	   */
	  protected $blog;

	  /**
	   * @var string
	   * @ORM\Column(length=100)
	   */
	  protected $title;

	  /**
	   * @var \DateTime
	   */
	  protected $date;

	  /**
	   * @var string
	   * @ORM\Column(type="text")
	   */
	  protected $content;

	  /**
	   * @var \Doctrine\Common\Collections\ArrayCollection<\TYPO3\Blog\Domain\Model\Comment>
	   * @ORM\OneToMany(mappedBy="post")
	   * @ORM\OrderBy({"date" = "DESC"})
	   */
	  protected $comments;

The same code with all annotations needed in plain Doctrine 2 to result in the same
metadata::

	/**
	 * @ORM\Entity(repositoryClass="TYPO3\Blog\Domain\Model\Repository\PostRepository")
	 * @ORM\Table(name="blog_post")
	 */
	class Post {

	  /**
	   * @var string
	   * @ORM\Id
	   * @ORM\Column(name="flow3_persistence_identifier", type="string", length=40)
	   */
	  protected $FLOW3_Persistence_Identifier;

	  /**
	   * @var \TYPO3\Blog\Domain\Model\Blog
	   * @ORM\ManyToOne(targetEntity="TYPO3\Blog\Domain\Model\Blog", inversedBy="posts")
	   * @ORM\JoinColumn(name="blog_blog", referencedColumnName="flow3_persistence_identifier")
	   */
	  protected $blog;

	  /**
	   * @var string
	   * @ORM\Column(type="string", length=100)
	   */
	  protected $title;

	  /**
	   * @var \DateTime
	   * @ORM\Column(type="datetime")
	   */
	  protected $date;

	  /**
	   * @var string
	   * @ORM\Column(type="text")
	   */
	  protected $content;

	  /**
	   * @var \Doctrine\Common\Collections\ArrayCollection<\TYPO3\Blog\Domain\Model\Comment>
	   * @ORM\OneToMany(targetEntity="TYPO3\Blog\Domain\Model\Comment", mappedBy="post",
	    cascade={"all"}, orphanRemoval="true")
	   * @ORM\OrderBy({"date" = "DESC"})
	   */
	  protected $comments;

Schema management
=================

Doctrine offers a *Migrations* system as an add-on part of its DBAL for versioning of
database schemas and easy deployment of changes to them. There exist a number of commands
in the FLOW3 CLI toolchain to create and deploy migrations.

A Migration is a set of commands that bring the schema from one version to the next. In
the simplest form that means creating a new table, but it can be as complex as renaming a
column and converting data from one format to another along the way. Migrations can also
be reversed, so one can migrate up and down.

Each Migration is represented by a PHP class that contains the needed commands. Those
classes come with the package they relate to, they have a name that is based on the time
they were created. This allows correct ordering of migrations coming from different
packages.

Query the schema status
-----------------------

To learn about the current schema and migration status, run the following command:

.. code-block:: bash

	$ ./flow3 flow3:doctrine:migrationstatus

This will produce output similar to the following, obviously varying depending on the
actual state of schema and active packages:

*Example: Migration status report*

.. code-block:: text

	 == Configuration
	    >> Name:                                               Doctrine Database Migrations
	    >> Database Driver:                                    pdo_mysql
	    >> Database Name:                                      flow3
	    >> Configuration Source:                               manually configured
	    >> Version Table Name:                                 flow3_doctrine_migrationstatus
	    >> Migrations Namespace:                               TYPO3\FLOW3\Persistence\Doctrine\Migrations
	    >> Migrations Target Directory:                        /path/to/Data/DoctrineMigrations
	    >> Current Version:                                    0
	    >> Latest Version:                                     2011-06-13 22:38:37 (20110613223837)
	    >> Executed Migrations:                                0
	    >> Available Migrations:                               1
	    >> New Migrations:                                     1

	 == Migration Versions
	    >> 2011-06-13 22:38:37 (20110613223837)                not migrated

Whenever a version number needs to be given to a command, use the short form as shown in
parentheses in the output above. The migrations directory in the output is only used when
creating migrations, see below for details on that.

Deploying migrations
--------------------

On a pristine database it is very easy to create the tables needed with the following
command:

.. code-block:: bash

	$ ./flow3 flow3:doctrine:migrate

This will result in output that looks similar to the following:

.. code-block:: text

	Migrating up to 20110613223837 from 0

	  ++ migrating 20110613223837

	     -> CREATE TABLE flow3_resource_resourcepointer (hash VARCHAR(255) NOT NULL, ⏎
	     PRIMARY KEY(hash)) ENGINE = InnoDB
	     -> ALTER TABLE flow3_resource_resource ADD FOREIGN KEY ⏎
	     (flow3_resource_resourcepointer) REFERENCES flow3_resource_resourcepointer(hash)

	  ++ migrated (1.31s)

	  ------------------------

	  ++ finished in 1.31
	  ++ 1 migrations executed
	  ++ 6 sql queries

This will deploy all migrations delivered with the currently active packages to the
configured database. During that process it will display all the SQL statements executed
and a summary of the deployed migrations at the and. You can do a dry run using:

.. code-block:: bash

	$ ./flow3 flow3:doctrine:migrate --dry-run

This will result in output that looks similar to the following:

.. code-block:: text

	Executing dry run of migration up to 20110613223837 from 0

	  ++ migrating 20110613223837

	     -> CREATE TABLE flow3_resource_resourcepointer (hash VARCHAR(255) NOT NULL, ⏎
	     PRIMARY KEY(hash)) ENGINE = InnoDB
	     -> ALTER TABLE flow3_resource_resource ADD FOREIGN KEY ⏎
	     (flow3_resource_resourcepointer) REFERENCES flow3_resource_resourcepointer(hash)

	  ++ migrated (0.09s)

	  ------------------------

	  ++ finished in 0.09
	  ++ 1 migrations executed
	  ++ 6 sql queries

to see the same output but without any changes actually being done to the database. If you
want to inspect and possibly adjust the statements that would be run and deploy manually,
you can write to a file:

.. code-block:: bash

	$ ./flow3 flow3:doctrine:migrate --path <write/here/the.sql>

This will result in output that looks similar to the following:

.. code-block:: text

	Writing migration file to "<write/here/the.sql>"

.. important::

	When actually making manual changes, you need to keep the ``flow3_doctrine_migrationstatus``
	table updated as well! This is done with the ``flow3:doctrine:migrationversion`` command.
	It takes a ``--version`` option together with either an ``--add`` or ``--delete`` flag to
	add or remove the given version in the ``flow3_doctrine_migrationstatus`` table. It does
	not execute any migration code but simply marks the given version as migrated or not.

Reverting migrations
--------------------

The migrate command takes an optional ``--version`` option. If given, migrations will be
executed up or down to reach that version. This can be used to revert changes, even
completely:

.. code-block:: bash

	$ ./flow3 flow3:doctrine:migrate --version <version> --dry-run

This will result in output that looks similar to the following:

.. code-block:: text

	Executing dry run of migration down to 0 from 20110613223837

	  -- reverting 20110613223837

	     -> ALTER TABLE flow3_resource_resource DROP FOREIGN KEY
	     -> DROP TABLE flow3_resource_resourcepointer
	     -> DROP TABLE flow3_resource_resource
	     -> DROP TABLE flow3_security_account
	     -> DROP TABLE flow3_resource_securitypublishingconfiguration
	     -> DROP TABLE flow3_policy_role

	  -- reverted (0.05s)

	  ------------------------

	  ++ finished in 0.05
	  ++ 1 migrations executed
	  ++ 6 sql queries

Executing or reverting a specific migration
-------------------------------------------

Sometimes you need to deploy or revert a specific migration, this is possible as well.

.. code-block:: bash

	$ ./flow3 flow3:doctrine:migrationexecute --version <20110613223837> --direction <direction> --dry-run

This will result in output that looks similar to the following:

.. code-block:: text

	  -- reverting 20110613223837

	     -> ALTER TABLE flow3_resource_resource DROP FOREIGN KEY
	     -> DROP TABLE flow3_resource_resourcepointer
	     -> DROP TABLE flow3_resource_resource
	     -> DROP TABLE flow3_security_account
	     -> DROP TABLE flow3_resource_securitypublishingconfiguration
	     -> DROP TABLE flow3_policy_role

	  -- reverted (0.41s)

As you can see you need to specify the migration ``--version`` you want to execute. If you
want to revert a migration, you need to give the ``--direction`` as shown above, the
default is to migrate "up". The ``--dry-run`` and and ``--output`` options work as with
``flow3:doctrine:migrate``.

Creating migrations
-------------------

Migrations make the schema match when a model changes, but how are migrations created?
The basics are simple, but rest assured that database details and certain other things
make sure you'll need to practice... The command to scaffold a migration is the following:

.. code-block:: bash

	$ ./flow3 flow3:doctrine:migrationgenerate

This will result in output that looks similar to the following:

.. code-block:: text

	Generated new migration class to "/path/to/Data/DoctrineMigrationsVersion20110624143847.php".

Looking into that file reveals a basic migration class already filled with the differences
detected between the current schema and the current models in the system:

*Example: Migration generated based on schema/model differences* ::

	namespace TYPO3\FLOW3\Persistence\Doctrine\Migrations;

	use Doctrine\DBAL\Migrations\AbstractMigration,
	  Doctrine\DBAL\Schema\Schema;

	/**
	 * Auto-generated Migration: Please modify to your need!
	 */
	class Version20110624143847 extends AbstractMigration {

	  /**
	   * @param Schema $schema
	   * @return void
	   */
	  public function up(Schema $schema) {
	      // this up() migration is autogenerated, please modify it to your needs
	    $this->abortIf($this->connection->getDatabasePlatform()->getName() != "mysql");

	    $this->addSql("CREATE TABLE party_abstractparty (…) ENGINE = InnoDB");
	  }

	  /**
	   * @param Schema $schema
	   * @return void
	   */
	  public function down(Schema $schema) {
	      // this down() migration is autogenerated, please modify it to your needs
	    $this->abortIf($this->connection->getDatabasePlatform()->getName() != "mysql");

	    $this->addSql("DROP TABLE party_abstractparty");
	  }
	}

To create an empty migration skeleton, pass ``--diff-against-current 0`` to the command.

.. important::

	The directory generated migrations are written to is only used when creating migrations.
	The migrations visible to the system are read from *Migrations/<DbPlatForm>* in each
	package. The *<DbPlatform>* represents the target platform, e.g. ``Mysql`` (as in Doctrine
	DBAL but with the first character uppercased).

After you generated a migration, you will probably need to clean up a little, as there
might be differences being picked up that are not useful or can be optimized. An example
is when you rename a model: The migration will drop the old table and create the new one,
but what you want instead is to *rename* the table. Also you must to make sure each finished
migration file only deals with one package and then move it to the *Migrations* directory
in that package. This way different packages can be mixed and still a reasonable migration
history can be built up.

Schema updates without migrations
---------------------------------

Migrations are the recommended and preferred way to bring your schema up to date. But
there might be situations where their use is not possible (e.g. no migrations are
available yet for the RDBMS you are using) or not wanted (because of, um… something).
The there are two simple commands you can use to create and update your schema.

To create the needed tables you can call ``./flow3 flow3:doctrine:create`` and it will
create all needed tables. If any target table already exists, an error will be the
result.

To update an existing schema to match with the current mapping metadata (i.e. the current
model structure), use ``./flow3 flow3:doctrine:update`` to have missing items (fields,
indexes, ...) added. There is a flag to disable the safe mode used by default. In safe mode,
Doctrine tries to keep existing data as far as possible, avoiding lossy actions.

.. warning::

	Be careful, the update command might destroy data, as it could drop tables and fields
	irreversibly.

.. tip::

	Both commands also support ``--output <write/here/the.sql>`` to write the SQL
	statements to the given file instead of executing it.

Generic Persistence
===================

What is now called *Generic* Persistence, used to be the only persistence layer in FLOW3.
Back in those days there was no ORM available that fit our needs. That being said, with
the advent of Doctrine 2, your best bet as a PHP developer is to use that instead of any
home-brewn ORM.

When your target is not a relational database, things look slightly different, which is
why the "old" code is still available for use, primarily by alternative backends like the
ones for CouchDB or Solr, that are available. Using the Generic persistence layer to
target a RDBMS is still possible, but probably only useful for rare edge cases.

Switching to Generic Persistence
--------------------------------

To switch back to Generic persistence on SQLite using PDO you need to configure FLOW3 like
this.

*Objects.yaml*:

.. code-block:: yaml

	TYPO3\FLOW3\Persistence\PersistenceManagerInterface:
	  className: 'TYPO3\FLOW3\Persistence\Generic\PersistenceManager'

	TYPO3\FLOW3\Persistence\QueryResultInterface:
	  scope: prototype
	  className: 'TYPO3\FLOW3\Persistence\Generic\QueryResult'

*Settings.yaml*:

.. code-block:: yaml

	FLOW3:
	  persistence:
	    doctrine:
	      enable: FALSE
	    backendOptions:
	      dataSourceName: 'sqlite:%FLOW3_PATH_DATA%Persistent/Objects.db'
	      username: ''
	      password: ''
	      # set the following to null to have them ignored
	      driver: ''
	      path: ''
	      dbname: ''

Using different database systems is possible, as long as there is a PDO driver available
in PHP. The syntax to use for ``dataSourceName`` depends on the PDO driver used, consult the
PHP documentation for that.

When installing other backend packages, like CouchDB, the needed object configuration
should be contained in them, for the connection settings, consult the package's
documentation.

Metadata mapping
----------------

The persistence layer needs to know a lot about your code to be able to persist it. In
FLOW3, the needed data is given in the source code through annotations, as this aligns
with the philosophy behind the framework.

Annotations for the Generic Persistence
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

The following table lists all annotations used by the persistence framework with their name,
scope and meaning:

:title:`Persistence-related code annotations`

+------------------+----------+----------------------------------------------------------+
+ Annotation       + Scope    + Meaning                                                  +
+==================+==========+==========================================================+
+ ``Entity``       + Class    + Declares a class as an Entity.                           +
+------------------+----------+----------------------------------------------------------+
+ ``ValueObject``  + Class    + Declares a class as a Value Object, allowing the         +
+                  +          + persistence framework to reuse an existing object if one +
+                  +          + exists.                                                  +
+------------------+----------+----------------------------------------------------------+
+ ``@var``         + Variable + Is used to detect the type a variable has.               +
+------------------+----------+----------------------------------------------------------+
+ ``Transient``    + Variable + Makes the persistence framework ignore the variable.     +
+                  +          + Neither will it's value be persisted, nor will it be     +
+                  +          + touched during reconstitution.                           +
+------------------+----------+----------------------------------------------------------+
+ ``Identity``     + Variable + Marks the variable as being relevant for determining     +
+                  +          + the identity of an object in the domain.                 +
+------------------+----------+----------------------------------------------------------+
+ ``Lazy``         + Class,   + When reconstituting the value of this property will be   +
+                  + Variable + loaded only when the property is used. Note: This is only+
+                  +          + supported for properties of type ``\SplObjectStorage``   +
+                  +          + and objects (marked with ``Lazy`` in their source code,  +
+                  +          + see below).                                              +
+------------------+----------+----------------------------------------------------------+

Enabling Lazy Loading
---------------------

If a class should be able to be lazy loaded by the PDO backend, you need to annotate it
with ``@lazy`` in the class level docblock. This is done to avoid creating proxy classes
for objects that should never be lazy loaded anyway. As soon as that annotation is found,
AOP is used to weave lazy loading support into your code that intercepts all method calls
and initializes the object before calling the expected method. Such a proxy class is a
subclass of your class, as such it work fine with type hinting and checks and can be used
the same way as the original class.

To actually mark a property for lazy loading, you need to add the ``@lazy`` annotation to
the property docblock in your code. Then the persistence layer will skip loading the data
for that object and the object properties will be thawed when the object is actually used.

:title:`How @lazy annotations interact`

+-----------+-----------+----------------------------------------------------------------+
+ Class     + Property  + Effect                                                         +
+===========+===========+================================================================+
+ ``Lazy``  + ``Lazy``  + The class' instances will be lazy loadable, and properties of  +
+           +           + that type will be populated with a lazy loading proxy.         +
+-----------+-----------+----------------------------------------------------------------+
+ ``Lazy``  + *none*    + The class' instances will be lazy loadable, but that           +
+           +           + possibility will not be used.                                  +
+-----------+-----------+----------------------------------------------------------------+
+ *none*    + ``Lazy``  + ``\SplObjectStorage`` will be reconstituted as a lazy loading  +
+           +           + proxy, for other types nothing happens.                        +
+           +           +                                                                +
+           +           + Properties of type ``\SplObjectStorage`` can always be         +
+           +           + lazy-loaded by adding the ``Lazy`` annotation on the property  +
+           +           + only.                                                          +
+           +           +                                                                +
+           +           + How and if lazy-loading is handled by alternative backends is  +
+           +           + up to the implementation.                                      +
+-----------+-----------+----------------------------------------------------------------+

Schema management
-----------------

For the PDO backend that comes with FLOW3, the needed tables are set up automatically.
When models are changed, no adjustments to the schema are needed. Effectively the schema
is maintenance-free. If you ever need to create or fix the schema manually, have a look at
*Resources/Private/Persistence/SQL/DDL.sql* in the FLOW3 package.

Whether other backends implement automatic schema management is up to the developers,
consult the documentation of the relevant backend for details.

Inside the Generic Persistence
------------------------------

To the domain code the persistence handling transparent, aside from the need to add a few
annotations. The custom repositories are a little closer to the inner workings of the
framework, but still the inner workings are very invisible. This is how it is supposed to
be, but a little understanding of how persistence works internally can help understand
problems and develop more efficient client code.

Persisting a Domain Object
~~~~~~~~~~~~~~~~~~~~~~~~~~

After an object has been added to a repository it will be seen when FLOW3 calls
``persistAll()`` at the end of a script run. Internally all instances implementing the
``\TYPO3\FLOW3\Persistence\RepositoryInterface`` will be fetched and asked for the objects
they hold. Those will then be handed to the persistence backend in use and processed by
it.

FLOW3 defines interfaces for persistence backends and queries, the details of how objects
are persisted and queried are up to the persistence backend implementation. Have a look at
the documentation of the respective package for more information. The following diagram
shows (most of) the way an object takes from creation until it is persisted when using the
FLOW3 default backend:

.. figure:: /Images/TheDefinitiveGuide/PartIII/Persistence_PersistenceProcess.png
	:align: center
	:width: 400pt
	:alt: Object persistence process

	Object persistence process

Keep in mind that the diagram omits some details like dirty checking on objects and how
exactly objects and their properties are stored.

Querying the Storage Backend
~~~~~~~~~~~~~~~~~~~~~~~~~~~~

As we saw in the introductory example there is a query mechanism available that provides
easy fetching of objects through the persistence framework. You ask for instances of a
specific class that match certain filters and get back an array of those reconstituted
objects. Here is a diagram of the internal process when using the FLOW3 default backend:

.. figure:: /Images/TheDefinitiveGuide/PartIII/Persistence_QueryProcess.png
	:align: center
	:width: 400pt
	:alt: Object querying and reconstitution process

	Object querying and reconstitution process

For the developer the complexity is hidden between the query's ``execute()`` method and
the array of objects being returned.


-----

.. [#] An alternative would have been to do an implicit persist call before a query, but
	that seemed to be confusing.