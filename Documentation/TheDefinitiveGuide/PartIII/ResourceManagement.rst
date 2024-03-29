===================
Resource Management
===================

.. sectionauthor:: Robert Lemke <robert@typo3.org>


Traditionally a PHP application deals directly with all kinds of files. Realizing a file
upload is usually an excessive task because you need to create a proper upload form, deal
with deciphering the ``$_FILES`` superglobal and move the uploaded file from the temporary
location to a safer place. You also need to analyze the content (is it safe?), control web
access and ultimately delete the file when it's not needed anymore.

FLOW3 relieves you of this hassle and lets you deal with simple ``Resource`` objects
instead. File uploads are handled automatically, enforcing the restrictions which were
configured by means of validation rules. The publishing mechanism was designed to support
a wide range of scenarios, starting from simple publication to the local file system up to
fine grained access control and distribution to one or more content delivery networks.
This all works without any further ado by you, the application developer.

Static Resources
================

FLOW3 packages may provide any amount of static resources. They might be images,
stylesheets, javascripts, templates or any other file which is used within the application
or published to the web. Static resources may either be public or private:

* *public resources* are automatically mirrored to the public web directory and are publicly
  accessible without any restrictions (provided you know the filename)
* *private resources* are not published by default. They can either be used internally (for
  example as templates) or published with certain access restrictions.

Whether a static package resource is public or private is determined by its parent
directory. For a package *Acme.Demo* the public resources reside in a folder called
*Acme.Demo/Resources/Public/* while the private resources are stored in
*Acme.Demo/Resources/Private/*. The directory structure below *Public* and *Private* is up
to you.

Persistent Resources
====================

Data which was uploaded by a user or generated by your application is called a *persistent
resource*. Although these resources are usually stored as files, they are never referred
to by their path and filename directly but are represented by ``Resource`` objects.

.. note::

	It is important to completely ignore the fact that resources are stored as files
	somewhere in FLOW3's directory structure – you should only deal with resource objects.

New persistent resources can be created by either importing or uploading a file. In either
case the result is a new ``Resource`` object which can be attached to any other object. A
resource exists as long as the ``Resource`` object is connected to another entity or value
object which is persisted. If a resource is not attached to any other persisted object,
its data will be permanently removed by a cleanup task.

.. note:: Garbage collecton of unused files is not yet implemented.

Importing Resources
-------------------

Importing resources is one way to create a new resource object. The ``ResourceManager``
provides a simple API method for this purpose:

*Example: Importing a new resource* ::

	class ImageController {

		/**
		 * @FLOW3\Inject
		 * @var \TYPO3\FLOW3\Resource\ResourceManager
		 */
		protected $resourceManager;

		// ... more code here ...

		/**
		 * Imports an image
		 *
		 * @param string $imagePathAndFilename
		 * @return void
		 */
		public function importImageAction($imagePathAndFilename) {
			$newResource = $this->resourceManager->importResource($imagePathAndFilename);

			$newImage = new \Acme\Demo\Domain\Model\Image();
			$newImage->setOriginalResource($newResource);

			$this->imageRepository->add($newImage);
		}
	}

The ``ImageController`` in our example provides a method to import a new image. Because an
image consists of more than just the image file (we need a title, caption, generate a
thumbnail, ...) we created a whole new model representing an image. The imported resource
is considered as the "original resource" of the image and the ``Image`` model could easily
provide a "thumbnail resource" for a smaller version of the original.

This is what happens in detail while executing the ``importImageAction`` method:

1. The URI (in our case an absolute path and filename) is passed to the ``importResource()``
   method which analyzes the file found at that location.
2. The file is imported into FLOW3's persistent resources storage  using the sha1 hash over
   the file content as its filename. If a file with exactly the same content is imported
   it will reuse the already stored resource.
3. The Resource Manager returns a new ``Resource`` object which refers to the newly
   imported file.
4. A new ``Image`` object is created and the resource is attached to it.
5. The image is added to the ``ImageRepository``. Only from now on the new image and the
   related resource will be persisted. If we omitted that step, the image, the resource
   and in the end the imported file would be discarded at the end of the script run.

In order to delete a resource just disconnect the resource object from the persisted
object, for example by unsetting ``originalResource`` in the ``Image`` object.

Resource Uploads
----------------

The second way to create new resources is uploading them via a POST request. FLOW3's MVC
framework detects incoming file uploads and automatically converts them into ``Resource``
objects. In order to persist an uploaded resource you only need to persist the resulting
object.

Consider the following Fluid template:

.. code-block:: html

	<f:form method="post" action="create" object="{newImage}" name="newImage"
		enctype="multipart/form-data">
		<f:form.textbox property="image.title" value="My image title" />
		<f:form.upload property="image.originalResource" />
		<f:form.submit value="Submit new image"/>
	</f:form>


This form allows for submitting a new image which consists of an image title and the image
resource (e.g. a JPEG file). The following controller can handle the submission of the above
form::

	class ImageController {

	   /**
	    * Creates a new image
	    *
	    * @param \Acme\Demo\Domain\Model\Image $newImage The new image
	    * @return void
	    */
	   public function createAction(\Acme\Demo\Domain\Model\Image $newImage) {
	      $this->imageRepository->add($newImage);
	      $this->forward('index');
	   }
	}

Provided that the ``Image`` class has a ``$title`` and a ``$originalResource`` property and
that they are accessible through ``setTitle()`` and ``setOriginalResource()`` respectively the
above code will work just as expected.

.. tip::

	There are more API functions in FLOW3's ``ResourceManager`` which allow for retrieving
	additional information about the circumstances of resource uploads. Please refer to
	the API documentation for further details.

Resource Publishing
===================

The process of *resource publishing* makes the resources in the system available,
and to provide an URL by which the given resource can be retrieved by the client.

Static Resources
----------------

Static resources (provided by packages) are published to the web directory on the first
script run and whenever packages are activated or deactivated.

.. note:: Internally, we do not copy all the resource files but just generate a symlink
	by default. This makes sure all changes you do in the *Resources/Public/* folder
	of your package are automatically visible.

Published static resources can be used in Fluid templates via the built-in resource view
helper:

.. code-block:: html

	<img src="{f:uri.resource(path: 'Images/Icons/FooIcon.png', package: 'Acme.Demo')}" />

Note that the ``package`` parameter is optional and defaults to the
package containing the currently active controller.

.. warning::

	Although it might be tempting shortcut, never refer to the resource files directly
	through a URL like ``_Resources/Static/Packages/Acme.Demo/Images/Icons/FooIcon.png``
	because you can't really rely on this path. Always use the resource view helper
	instead.

Persistent Resources
--------------------

Persistent resources are published on demand because FLOW3 cannot know which resources are
meant to be public and which ones need to be kept private. The trigger for publishing
persistent resources is the generation of its public web URI. A very common way to do that
is displaying a resource in a Fluid template:

.. code-block: html

	<img src="{f:uri.resource(resource: image.originalResource)}" />

The resource view helper (``f:uri.resource`` ) will ask the ``ResourcePublisher`` for the
web URI of the resource stored in ``image.originalResource``. The publisher checks if the
given resource has already been published and if not publishes it right away.

A published persistent resource is accessible through a web URI like
``http://example.local/_Resources/Persistent/107bed85ba5e9bae0edbae879bbc2c26d72033ab.jpg``.
One advantage of using the sha1 hash of the resource content as a filename is that once the
resource changes it gets a new filename and is displayed correctly regardless of the cache
settings in the user's web browser. Search engines on the other hand prefer more meaningful
filenames. For these cases the resource view helper allows for defining a speaking title
for a resource URI:

.. code-block :: html

	<img src="{f:uri.resource(resource: image.originalResource, title: image.title)}" />

A URI produced by the above template would look like this:
``http://example.local/_Resources/Persistent/107bed85ba5e9bae0edbae879bbc2c26d72033ab/my-speaking-title.jpg``

You can define as many titles for each resource as you want – the resulting file is always
the same, identified by the sha1 hash.

.. note:: Internally, FLOW3 uses a rewrite rule to map the speaking titles to the hash files.

Mirror Mode
-----------

Publishing resources basically means copying files from a private location to the public
web directory. FLOW3 instead creates symbolic links, making the resource publishing
process fast.

If your operating system does not support symbolic links, you will not be able to use FLOW3.

Resource Stream Wrapper
=======================

Static resources are often used by packages internally. Typical use cases are templates,
XML, YAML or other data files and images for further processing. You might be tempted to
refer to these files by using one of the ``FLOW3_PATH_*`` constants or by creating a path
relative to your package. A much better and more convenient way is using FLOW3's built-in
stream package resources wrapper.

The following example reads the content of the file
``Acme.Demo/Resources/Private/Templates/SomeTemplate.html`` into a variable:

*Example: Accessing static resources* ::

	$template = file_get_contents(
		'resource://Acme.Demo/Private/Templates/SomeTemplate.html
	');

Likewise you might get into a situation where you need to programmatically access
persistent resources. The resource stream wrapper also supports these, all you need to do
is passing the resource hash:

*Example: Accessing persisted resources* ::

	$imageFile = file_get_contents('resource://' . $resource);

Note that you need to have a ``Resource`` object in order to access its file and that the
above example only works because ``Resource`` provides a ``__toString()`` method which
returns the resource's hash.

You are encouraged to use this stream wrapper wherever you need to access a static or
persisted resource in your PHP code.