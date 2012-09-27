Evernote Repository
===================

Moodle repository plugin to browse Evernote.

Features
--------

- Get access to the files contained in your notes.
- Browse all your notes.
- Browse your notebooks.
- Browse your tags.
- Browse your saved searchs.

Requirements
------------

Moodle 2.3.2 or greater.

Install
-------

- Request an API key from [Evernote Developers](http://dev.evernote.com/documentation/cloud/) and activate the key on their production services (step 1 and 4).
- Copy the content of this repository in repository/evernote.
- Go to your admin notifications page to install the plugin.
- Navigate to Settings > Site administration > Plugins > Repositories to enable it.
- Enter your consumer key and secret in the settings page.

Todo
----

- Support for file references.
- Enable paging (blocked by MDL-35664).
- Decrease the version required (ideally 2.3.0, investigate branch for 2.2).
- Set up a search field.
- Investigate advanced search possibilities.

License
-------

Licensed under the [GNU GPL License](http://www.gnu.org/copyleft/gpl.html)
