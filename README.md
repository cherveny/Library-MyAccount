# Library-MyAccount
PHP classes used for extraction of user and transaction data from OCLC's Illiad and ExLibris Voyager

The code has not been uploaded yet, but expect it within a few weeks.

This code stems from our efforts at UTSA to combine our Interlibrary loan system's user account page (OCLC Illiad) with our ILS (ExLibris Voyager) account page into a single, unified home page, containing all user account data.  There are two classes, one for Illiad and one for Voyager.  Both classes extract data on user information and transactions, Illiad via JSON, Voyager via XML via the Restful interface.  

Both classes make data available via public exposed objects, as well as offering a "pretty print" method as well, if you do not wish to create your own display routines.

There will be customizations needed to make things work with your system (such as your Illiad API key, matching used fields to what fields are used in your institution, etc), but, if nothing else, this code will hopefully provide you an example for how to write your own code to use these interfaces.

