# Imagehub

[![Software License][ico-license]](LICENSE)

The Imagehub is a [IIIF](https://iiif.io/) Presentation API compliant aggregator and web service for generating, storing and serving IIIF manifests based on the combination of a set of images stored in a Digital Asset Management service, such as [ResourceSpace](https://www.resourcespace.com/) and metadata retrieved from an OAI-PMH endpoint, for example a [Datahub](https://github.com/thedatahub/Datahub) application. It also relies on [Cantaloupe](https://cantaloupe-project.github.io/) for retrieving necessary image metadata to be used inside the manifests.

The imagehub is based around a set of high-resolution images, stored in a local drop folder and imported into a ResourceSpace application.



## Requirements

This project requires following dependencies:
* PHP >= 7.1.3
  * With the php-cli, php-xml and php-imagick extensions.
  * The [PECL Mongodb](https://pecl.php.net/package/mongodb) (PHP7) extension. Note that the _mongodb_ extension must be version 1.2.0 or higher. Notably, the package included in Ubuntu 16.04 (_php-mongodb_) is only at 1.1.5.

    To install PECL and mongodb:
      ```bash
      $ apt-get install php-pear
      $ pecl install mongodb
      ```
* MongoDB >= 3.2.10
* A local or remote [Datahub](https://github.com/VlaamseKunstcollectie/Datahub) installation
* A local or remote [ResourceSpace](https://www.resourcespace.com/) installation
* A local or remote [Cantaloupe](https://cantaloupe-project.github.io/) installation

## Install

Via Git:

```bash
$ git clone https://github.com/VlaamseKunstcollectie/Imagehub
$ cd Imagehub
$ composer install # Composer will ask you to fill in any missing parameters before it continues
```

You will be asked to configure the connection to your MongoDB database. You will need to provide these details (but can currently be skipped due to still being in development):

* The connection to your MongoDB instance (i.e. mongodb://127.0.0.1:27017)
* The username of the user (i.e. imagehub)
* The password of the user
* The database where your data will persist (i.e. imagehub)

Before you install, ensure that you have a running MongoDB instance, and you 
have created a user with the right permissions. From the 
[Mongo shell](https://docs.mongodb.com/getting-started/shell/client/) run these
commands to create the required artefacts in MongoDB:

```
> use imagehub
> db.createUser(
   {
     user: "imagehubuser",
     pwd: "imagehubpass",
     roles: [ "readWrite", "dbAdmin" ]
   }
)
```

You will also be asked to provide the URL's of the Datahub, Cantaloupe and ResourceSpace along with the ResourceSpace API user and key. 
Lastly, you need to provide the path of the local drop folder where images are stored (the images_folder parameter). This can be an absolute path or a path relative to the imagehub project folder.

If you want to run the imagehub for testing or development purposes, execute this command:

``` bash
$ php bin/console server:run
```

Use a browser and navigate to [http://127.0.0.1:8000](http://127.0.0.1:8000). 
You should now see the imagehub homepage.

Refer to the [Symfony setup documentation](https://symfony.com/doc/current/setup/web_server_configuration.html) 
to complete your installation using a fully featured web server to make your 
installation operational in a production environment.

## Usage

### Uploading images

The imagehub expects a local drop folder to be present containing JPEG-compressed PTIF-images. These images should not be placed inside a subfolder and their filenames should not have an extension. At the very least, all images are expected to have the EXIF-tag 'ImageDescription', containing a data PID that links the image to a record inside the Datahub.
 
### Preparing ResourceSpace

The metadata fields of your ResourceSpace installation should match the 'field' values of the 'exif_fields' and 'datahub_data_definition' arrays defined in app/config/resourcespace.yml.
If any of these fields are defined as a drop-down field in ResourceSpace, make sure all possible values in the drop down options have been added before proceeding to the next step.

Before importing the images into ResourceSpace, make sure to append the following lines to config.php inside the includes/ folder of your ResourceSpace installation:
```php
$file_checksums = false;
$filename_field = NULL;
```

### Adding images to ResourceSpace


In order to import the images from the local drop folder to ResourceSpace, run this command:
```bash
$ php app/console app:fill-resourcespace
```
This command will call the ResourceSpace API to list all images already present in ResourceSpace. It will then loop over all images in the local drop folder, validate them, read their EXIF-tags, compare both the image hash and the EXIF-tags to whatever is already present in ResourceSpace and update the image or EXIF-tags inside the ResourceSpace resource where needed.

You can run this command with the -v flag for verbose output.

### Generating IIIF manifests

Once ResourceSpace contains all images with the necessary metadata, you can run the following command in order to generate a manifests for each image:
```bash
$ php app/console app:generate-manifests
```
This command lists all images in ResourceSpace and for each image performs a call to the Datahub to fetch the necessary work-related data (title, description, creator, period, ...) along with any relations to other records.
It also calls Cantaloupe to get the height and width for each image. It then combines all the information to generate one manifest per image which it stores in MongoDB.

## Front end development

Front end workflows are managed via [yarn](https://yarnpkg.com/en/) and 
[webpack-encore](https://symfony.com/blog/introducing-webpack-encore-for-asset-management).

The layout is based on [Bootstrap 3.3](https://getbootstrap.com/docs/3.3/)
and managed via sass. The code can be found under `app/resources/public/sass`.

Javascript files can be found under `assets/js`. Dependencies are 
managed via `yarn`. Add vendor modules using `require`.

Files are build and stored in `web/build` and included in `app/Resources/views/base.html.twig`
via the `asset()` function.

The workflow configuration can be found in `webpack.config.js`.

Get started:

```
# Install all dependencies
$ yarn install

# Build everything in development
$ yarn run encore dev

# Watch files and build automatically
$ yarn run encore dev --watch

# Build for production
$ yarn run encore production
```

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.


## Authors

[All Contributors][link-contributors]

## Copyright and license

The Imagehub is copyright (c) 2019 by Vlaamse Kunstcollectie vzw.

This is free software; you can redistribute it and/or modify it under the 
terms of the The GPLv3 License (GPL). Please see [License File](LICENSE) for 
more information.

[ico-version]: https://img.shields.io/packagist/v/:vendor/:package_name.svg?style=flat-square
[ico-license]: https://img.shields.io/badge/license-GPLv3-brightgreen.svg?style=flat-square
[ico-travis]: https://img.shields.io/travis/:vendor/:package_name/master.svg?style=flat-square
[ico-scrutinizer]: https://img.shields.io/scrutinizer/coverage/g/:vendor/:package_name.svg?style=flat-square
[ico-code-quality]: https://img.shields.io/scrutinizer/g/:vendor/:package_name.svg?style=flat-square
[ico-downloads]: https://img.shields.io/packagist/dt/:vendor/:package_name.svg?style=flat-square

[link-packagist]: https://packagist.org/packages/:vendor/:package_name
[link-travis]: https://travis-ci.org/:vendor/:package_name
[link-scrutinizer]: https://scrutinizer-ci.com/g/:vendor/:package_name/code-structure
[link-code-quality]: https://scrutinizer-ci.com/g/:vendor/:package_name
[link-downloads]: https://packagist.org/packages/:vendor/:package_name
[link-author]: https://github.com/:author_username
[link-contributors]: ../../contributors
