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

File references
---------------

The repository supports file references, also called alias or shortcut, to your attachments. That means that you can use the file in several places in Moodle and as soon as you update this file in Evernote, it will also be updated in Moodle. You should just be aware that, in order for this feature to work, your notes have to be shared. Everytime you import a file as an 'alias/shortcut', a private link to the note is created. From that point on, anyone who has that link can access your note, and its content. Although we are not giving away the link to the note, it is possible to guess it while accessing the file imported in Moodle, so if your note contains sensitive information, do not import any file as 'alias/shortcut' from it.

At any time you can revoke access to the note and all the resources it contains via Evernote itself, simply look for "Stop sharing". You can also use the search query 'sharedate:*' to find all the notes that are shared.

Todo
----

- Enable paging (blocked by MDL-35664).
- Get the content of the note as a PDF.
- Being able to pick a note's URL property.
- Picking the public URL to a note.

License
-------

Licensed under the [GNU GPL License](http://www.gnu.org/copyleft/gpl.html)
