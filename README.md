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
- Search through your notes.
- Support for file references (alias/shortcut).

Requirements
------------

Moodle 2.3.2 or greater.

Advanced search
---------------

The search field supports the advanced search syntax common to Evernote applications. Find out more at [Using Evernote's advanced search operators](https://support.evernote.com/ics/support/KBAnswer.asp?questionID=535) and [Evernote Search Grammar](http://dev.evernote.com/documentation/cloud/chapters/search_grammar.php).

Install
-------

- Request a 'Full access' API key from [Evernote Developers](http://dev.evernote.com/doc/) and activate the key on their production servers (see menu Resources > Activate an API key).
- Copy the content of this repository in repository/evernote.
- Go to your admin notifications page to install the plugin.
- Navigate to Settings > Site administration > Plugins > Repositories to enable it.
- Enter your consumer key and secret in the settings page.

Note: The 'Full access' is required to read the content of your notes.

Todo
----

- Enable paging (blocked by MDL-35664).
- Get the content of the note as a PDF.
- Being able to pick a note's URL property.
- Picking the public URL to a note.

License
-------

Licensed under the [GNU GPL License](http://www.gnu.org/copyleft/gpl.html)
