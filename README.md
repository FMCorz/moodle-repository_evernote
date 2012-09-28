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

Requirements
------------

Moodle 2.3.0 or greater.

Advanced search
---------------

The search field supports the advanced search syntax common to Evernote applications. Find out more at [Using Evernote's advanced search operators](https://support.evernote.com/ics/support/KBAnswer.asp?questionID=535) and [Evernote Search Grammar](http://dev.evernote.com/documentation/cloud/chapters/search_grammar.php).

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
- Investigate backward compatibility for Moodle 2.2.

License
-------

Licensed under the [GNU GPL License](http://www.gnu.org/copyleft/gpl.html)
