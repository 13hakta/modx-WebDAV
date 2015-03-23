## WebDAV

WebDAV is a network media source for MODx Revolution.

## How to Export

First, clone this repository somewhere on your development machine:

`git clone http://github.com/13hakta/modx-WebDAV.git ./`

Then, create the target directory where you want to create the file.

Then, navigate to the directory modx-WebDAV is now in, and do this:

`git archive HEAD | (cd /path/where/I/want/my/new/repo/ && tar -xvf -)`

(Windows users can just do git archive HEAD and extract the tar file to wherever
they want.)

Then you can git init or whatever in that directory, and your files will be located
there!

## Configuration

Now, you'll want to change references to modx-WebDAV in the files in your
new copied-from-modx-WebDAV repo to whatever name of your new Extra will be. Once
you've done that, you can create some System Settings:

- 'mynamespace.core_path' - Point to /path/to/my/extra/core/components/extra/
- 'mynamespace.assets_url' - /path/to/my/extra/assets/components/extra/

Then clear the cache. This will tell the Extra to look for the files located
in these directories, allowing you to develop outside of the MODx webroot!

## Copyright Information

modx-WebDAV is distributed as GPL (as MODx Revolution is), but the copyright owner
(Vitaly Chekryzhev) grants all users of modx-WebDAV the ability to modify, distribute
and use modx-WebDAV in MODx development as they see fit, as long as attribution
is given somewhere in the distributed source of all derivative works.