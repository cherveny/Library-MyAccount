<?php

class illiadclass2 {
# My Account Replacement: Illiad
# Coded by Bruce A Orcutt, UTSA.
# Bruce.orcutt@utsa.edu (210) 458-6192
#  Code designed to connect to OCLC Illiad, extract basic information via the provided  web services, and give a "pretty print" method for displaying the data
# NOTE: IF DISTRIBUTING CODE OUTSIDE OF UTSA, REMOVE API KEY BELOW
# 
#

# Format of journals and books variables
# ======================================
#variable status      ||number in sub group (all) ||Illiad fields (all except URL, Reason)
# $journals	["pend"]			||	[#]			||	["TransactionNumber"]
#		 	["cancel"]			||	[#]			|| 	["TransactionStatus"]
#			["end"] 			||	[#]			|| 	["PhotoJournalTitle"]
#			["web"]				||	[#]			||	["URL"] (delivered to web ONLY)
#								||				|| 	["ReasonForCancellation"] (cancel only)
#								||				||	["tURL"] URL that shows the full transaction within Illiad
#---------------------------------------
#variable status      ||number in sub group (all) ||Illiad fields 
# $books		["pend"]		||	[#]			||	["TransactionNumber"]
#				["cancel"]		||	[#]			||	["TransactionStatus"]  
#				["end"]			||	[#]			||	["LoanTitle"]
#				["out"]			||	[#]			||	["ReasonForCancellation"] (cancel only)
#								||				||	["tURL"] URL that shows the full transaction within Illiad

# Public Methods
# 	__constructor	:	Creates  the object when using new
#	prettyPrint		:	Method to display results  in basic html
#	populateValues	:	Add data from web services


#
# Publics
#
	public	$journals				=	"";		# Holds the array of just journal requests
	public	$books					=	"";		# Holds the array of just book requests
# FOR FUTURE USE: list if a user is blocked. If/When implemented, will do a query into the Illiad database user table, grabbing the blocked field
	public	$blockingIssue			=	illiadclass2::biUNDEF;		

#
# Privates
#
	private $id						=	"";		# at UTSA, ABC123 id via shibboleth, at other institutions, whatever id is used with your Illiad service
	private $out					=	"";		# used to receive formatted html (may not need)
	private $jsonURL				=	"";		# base for web services via json to illiad
	private $context				=	"";		# Used for context for http stream
	private $entireHistory			= 	"";		# Users Entire Illiad history, in a giant JSON object
	private $request				=	"";		# Used to parse through foreach loop decoding JSON object

#
# Constants
#
# severe errors are emailed, usually should be set to a  systems group	
	const	ERRORMAIL			= 	"XXX.YYYY@ABC.edu"; 

# used to check if status type correct defined type
	private	$VALIDSTATUS		= 	array("pend","cancel","end","web","out");  

# URL to grab detailed status page in Illiad proper, transaction number appended to URL
# NOTE: This could change, based on configuration of Illiad.
# To get correct setting, log into your Illiad website, pull up an existing transaction, and verify the URL here compared to the URL here
	const	STATUSURL			= 	"https://your.illiad.web.server.edu/illiad/illiad.dll?Action=10&Form=72&Value="; 

# URL to grab delivered to web article PDFs. Transaction number appended to URL
# NOTE: This could change, based on configuration of Illiad.
# To get correct setting, log into your Illiad website, pull up an existing transaction that had an article delivered to the web, and compare to this URL
	const	DELIVERURL				= 	"https://your.illiad.web.server.edu/illiad/illiad.dll?Action=10&Form=75&Value=";

# API key: NOTE IF DISTRIBUTING CODE OUTSIDE UTSA, REMOVE THIS VALUE!
# To get your APIKEY, use the Illiad Customization Manager, see the Illiad User Guide documentation
	const	APIKEY					=	"ApiKey: XXXXX-XXXX-XXXX-XXXXX-XXXXXXXXXXXX";

# JSON Base URL, add id for web services URL
	const	JSONBASE				= 	"https://your.illiad.web.server.edu/IlliadWebPlatform/Transaction/UserRequests/";

# Binary flags for use with populateValues, sets which item types you want populated in the array (note, on PHP5.4 and above, can use the 0b0 format instead for clarity.
	const	bPEND					=	1;		# 0b00000001, 	flag for pending status
	const	bCANCEL					=	2;		# 0b00000010, 	flag for cancelled status
	const	bEND					=	4;		# 0b00000100, 	flag for end status
	const	bWEB					=	8;		# 0b00001000, 	flag for delivered to web
	const	bOUT					=	16;		# 0b00010000, 	flag for checked out status
	const	bBOOKS					=	32;		# 0b00100000, 	flag to include books
	const	bJOURNALS				=	64;		# 0b01000000, 	flag to include journals

# Return codes used in various places
	const	GOOD					=	 1;		# items found, no errors
	const	NONE					=	 2;		# No errors, yet items found
	const	ERROR					=	-1;		# Errors occured

# Search strings for array population, used in populateValues().  They are ODATA filters via ?$filter="
# To change what's included in a category, simply change these search strings
# NOTE: Spaces in the search strings, being in a URL, need to be expressed as %20.  
# Urlencode appears to break something in the URL on the Illiad side, so hand coding %20 is safer
# If you notice searches including more than what was expected, check for typos and formatting.  
# If Illiad doesn't like a search string, it ignores the entire $filter statement
	const	sCANCEL					=	"((TransactionStatus%20eq%20'Cancelled%20by%20ILL%20Staff')%20or%20(TransactionStatus%20eq%20'Cancelled%20by%20Customer'))";
	const	sOUT					=	"(TransactionStatus%20eq%20'Checked%20Out%20to%20Customer')";
	const	sEND					=	"(TransactionStatus%20eq%20'Request%20Finished')";
	const	sWEB					=	"(TransactionStatus%20eq%20'Delivered%20to%20Web')";
# For UTSA, any status not matching the searches above was decided to be "pending", so sPEND is the negation of all of the above
# Note, this is set below within populateValues().  Needs to be a variable, rather than constant, so we can use the other constants in setting the search string
	public	$sPEND				=	NULL;   # Needs to be a variable, rather than a const.  Allows for use to use the other constants. rather than hard coding
# Next search string constants for books vs journals, so we only get what they want
# We separate the two via PhotoJournalTitle (set on journals, blank on books) and LoanTitle (set on books, blank on journals)
# If we want both, then use true, and both returned
# NOTE: Illiad searches are CASE SENSITIVE on the keyworks "null" and "true"!
	const	sBOOKS				= 	"%20and%20(PhotoJournalTitle%20eq%20null)";
	const	sJOURNALS			=	"%20and%20(LoanTitle%20eq%20null)";
	const	sBOTH				=	"%20and%20true";

# Values for $blockingIssues
	const	biUNDEF				=	"-1";

# Articles Delivered to Web Expiration Times
# used in strtotime calculation. 
# ExpirationDate calculated by adding this value to TransactionDate when TransactionStatus is Delivered  to Web
# for UTSA, this is 30 days
	const	EXPIRETIME			=	"+30 days";

#
#
# CONSTRUCTOR
#
#
public function __construct($shibid) {
	# Object constructor, used automatically when creating a new object via new.
	# Initializes two members, books and journals, as described in comments at the top of this class.
	# FUTURE: populate blockingIssue with reason user is blocked from lending
	#
	# Parameters:
	#	$shibid	:	Illiad Userid.  In UTSA's case, uses the "abc123", which via shibboleth is in $_SERVER['uid']
	#
	# Returns:
	#	$illiadclass2::GOOD		:	No issues encountered
	#	$illiadclass2::NONE		:	No items to print of selected type
	#	$illiadclass2::ERROR	:	Error encountered
	#
	# shibid is passed in.  At UTSA, expected to be the environment id from shibboleth, sets shibid to $this->id for use in rest of class
	$this->id	=	$shibid;

	debug($this->id . "is the id<p>\n");

	# Ensure the ID is valid
	if (!($this->validateUserId($this->id))) {
		error_log("ID $this->id is not an abc123, aborting!");
		$this->mail_error("The ID, $this->id, returned by Shibboleth _SERVER(uid) is not a valid abc123"); 

		# in most cases, we use error return codes instead of Exceptions.  
		# Constructor is the exception, since can't have a return from a Constructor, except the new object
		throw new Exception("Your UserID appears to be invalid");
	} #end, validate
	
	$this->debug("I AM BORN\n");

	# Initialize the counts of all the subarray types to -1
	# This makes the arrays much easier to parse later.  
	# Stuffed into a method now to clean the code a little
	$this->arrayInit();

	# Set the URL used for all illiad WEB Services (JSONBASE constant, followed by USERID)
	$this->jsonURL = $this::JSONBASE . $this->id;

} # end, constructor	

public function prettyPrint($index,$jORb) {
	# method to print html formatted Illiad elements
	# $index: 	the type of status.  it must be: pend, cancel, end, web, pend, out
	# $jORb:	a j or a b, states if printing books or articles
	# (return):	The formatted html text, -1 on error

	# Local Variables
	$theHtml	=	NULL;		# the final html to be returned from the function
	$iteration	=	NULL;		# holds each iteration of the $items variable, in the $foreach loop
	$typeIndex	=	NULL;		# becomes PhotoJournalTitle or LoanTitle, based on j or b setting in $jORb, saves extra coding
	$items		=	NULL;		# holds books or journals, depending on $jORb

	# illiad is the master div class
	$theHtml	=	'<div class="illiad">';

	# test if a book or a journal based on flag passed in
	if ($jORb == "j" ) {
		$typeIndex	=	"PhotoJournalTitle";
		$items		=	$this->journals[$index];
	} # end of j if
	
	else if ($jORb	==	"b" ) {
		$typeIndex	=	"LoanTitle";
		$items 		=	$this->books[$index];
	} # end of else if b
	
	else { 	
		# not a j or a b, thus an error
		error_log ("Illiad replacement: pretty_print() joORb set to other than j or b.");
		$this->mail_error("Illiad replacement: pretty_print() joORb set to other than j or b.");
		return $this::ERROR;
	} # end else, not a j or a b

	# Make sure we have a valid status type	
	if (!in_array($index, $this->VALIDSTATUS)) { 
		# $index isn't a defined type! BAIL!
		error_log ("Illiad replacement: pretty_print() called with invalid status type $index.");
		$this->mail_error("Illiad replacement: pretty_print() called with invalid status type $index.");
		return $this::ERROR;
	} # if, in_array

# Verify there's actually something to print
	if ($items[0]["TransactionNumber"] == -1) {
		return $this::NONE;
	} 

	$count = 0;

	# BIG LOOP, go through each of the books/journals, print appropriate fields based on types
	foreach ($items	as	$iteration) {
		# An entry for each item
	
	switch ($index) {
		# fields based on type

		case "web":
		# journals that have been "delivered to web"
		# web is always a journal
		# includes URL field for URL to delivered PDF file
	
				if ($jORb	==	"b") {
				# check if a book, if it is, exit in error, not valid.
					error_log("ERROR: prettyPrint web called with type b, only valid with j\n");
					return $this::ERROR;  # return error
				} # end, if jORb is b

				if ($count	==	0) { 
				# Mark the group if first time
					$theHtml	=	$theHtml . "<H2>Journals Delivered to the Web</H2>"; 
					$count++;
				}  # end, if count 0 for headers

				# Stuff item into a definition list
				$theHtml	=	$theHtml . '<dl>';

				# Transaction Number, URLified to Illiad to get whole status
				$theHtml	=	$theHtml	.	'<dt>Transaction number:</dt> '	.	'<dd>'	.
								'<a href="' . $iteration["tURL"]	.	'">'	.	$iteration["TransactionNumber"]  
								.	'</a>'	.	'</dd>';

				# Title, $typeIndex set above from $jORb
				$theHtml	=	$theHtml	.	'<dt>Title:</dt>'	.	'<dd>'	.	$iteration[$typeIndex]			.	'</dd>';
				$theHtml	=	$theHtml	.	'<dt>Article:</dt>'	.	'<dd>'	.	$iteration["PhotoArticleTitle"] .	'</dd>';

				# Verify if author is set before displaying 
				if (isset($iteration["PhotoArticleAuthor"]) &&  ($iteration["PhotoArticleAuthor"]	!=	"") )	{
					$theHtml	=	$theHtml	.	'<dt>Author:</dt>'	.	'<dd>'	.	$iteration["PhotoArticleAuthor"]	.	'</dd>';
				} # end, author

				# Item Type
				if (isset($iteration["DocumentType"]) &&  ($iteration["DocumentType"] != "") )	{
					$theHtml	=	$theHtml	.	'<dt>Item Type:</dt>'	.	'<dd>'	.	$iteration["DocumentType"]	.	'</dd>';
				}
				
				# URL for delivered to web content
				$theHtml	=	$theHtml	.	'<dt>Your Article:</dt>'	.	'<dd>'	.	'<a href="'	.	$iteration["URL"]	
											.	'">Your Delivered Article</a>'	.	'</dd>';

				# Expiration Date
				if (isset($iteration["ExpirationDate"]))	{
					$theHtml	=	$theHtml	.	'<dt>Expiration Date:</dt>'	.	'<dd>'	.	$iteration["ExpirationDate"]	.	'</dd>';
				}

				# close the definition list				
				$theHtml	=	$theHtml	.	'</dl>';				
				
			break; # web

			case "cancel":
			# item request is cancelled (by user or by staff)
			# includes cancellation reason field
				
				if ($count	==	0) { 
				# Mark the group if first time
					if ($jORb	==	"j")	{
						$theHtml	=	$theHtml	.	"<H2>Journals Cancelled</H2>"; 
					}
					
					else	{
						$theHtml	=	$theHtml	.	"<H2>Books Cancelled</H2>"; 
					}
					$count++;
					
				}  # end, if count 0 for headers

				# Stuff item into a definition list
				$theHtml	=	$theHtml	.	'<dl>';

				# Transaction Number, URLified to Illiad to get whole status
				$theHtml	=	$theHtml	.	'<dt>Transaction number:</dt> ' 
								.	'<dd>'	.	'<a href="'	.	$this::STATUSURL 
								.	$iteration["TransactionNumber"]	.	'">'	.	$iteration["TransactionNumber"] 
								.  '</a>'	.	'</dd>';

				# Title, $typeIndex set above from $jORb
				$theHtml	=	$theHtml	.	'<dt>Title:</dt>'	.	'<dd>'	.	$iteration[$typeIndex]	.	'</dd>';
				
				# If a journal, article title
				if ($jORb	==	"j") {
				# if a journal, add one more field, article title
					$theHtml	=	$theHtml	.	'<dt>Article:</dt>' . '<dd>' . $iteration["PhotoArticleTitle"]	.	'</dd>';
				} # end, if journal

				# Cancellation Reason
				$theHtml	=	$theHtml	.	'<dt>Cancellation Reason:</dt><dd>'	.	$iteration["ReasonForCancellation"]	.	'</dd>';
				
				if ($jORb	==	"j")	{
				# if a journal, add one more field, article title
					$theHtml	=	$theHtml	.	'<dt>Article:</dt>'	.	'<dd>'	.	$iteration["PhotoArticleTitle"]	.	'</dd>';
					
					# Verify if author is set before displaying 
					if (isset($iteration["PhotoArticleAuthor"])	&&	($iteration["PhotoArticleAuthor"]	!=	"") ){
						$theHtml	=	$theHtml	.	'<dt>Author:</dt>'	.	'<dd>'	.	$iteration["PhotoArticleAuthor"]	.	'</dd>';
					} # end, if, author
					
				} # end, if journal
				
				else {
					# Verify if author is set before displaying 
					if (isset($iteration["LoanAuthor"])	&&	($iteration["LoanAuthor"]	!=	"") )	{
						$theHtml	=	$theHtml	.	'<dt>Author:</dt>'	.	'<dd>'	.	$iteration["LoanAuthor"]	.	'</dd>';
					} # end, if author
					
				} # end, else book

				# Item Type
				if (isset($iteration["DocumentType"])	&&	($iteration["DocumentType"]	!=	"") )	{
					$theHtml	=	$theHtml	.	'<dt>Item Type:</dt>'	.	'<dd>'	.	$iteration["DocumentType"]	.	'</dd>';
				} # end, item type

				# end the definition list
				$theHtml	=	$theHtml	.	'</dl>';
				
			break; # cancel

			case "out":
			# a checked out item (or arrived and waiting for customer pickup)
			# an out item only referes to books

				if ($jORb	==	"j")	{
				# check if a journal, if it is, exit in error, not valid.
					error_log("ERROR: prettyPrint out called with type j, only valid with b\n");
					return -1;  # return error
				} # end, if jORb is b

				if ($count	==	0)	{ 
				# Mark the group if first time
					$theHtml	=	$theHtml	.	"<H2>Books Checked Out or Available to be Picked Up</H2>"; 
					$count++;
				} # end header

				# Stuff item into a definition list
				$theHtml	=	$theHtml	.	'<dl>';

				# Transaction Number, URLified to Illiad to get whole status
				$theHtml	=	$theHtml	.	'<dt>Transaction number:</dt> '	.	'<dd>'	.
								'<a href="' . $this::STATUSURL	.	$iteration["TransactionNumber"]
								. '">'	.	$iteration["TransactionNumber"]	.	'</a>'	.	'</dd>';

				# Title, $typeIndex set above from $jORb
				$theHtml	=	$theHtml	.	'<dt>Title:</dt>'	.	'<dd>'	.	$iteration[$typeIndex]	.	'</dd>';
				
				if (isset($iteration["LoanAuthor"])	&&	($iteration["LoanAuthor"]	!=	"") )	{
					$theHtml	=	$theHtml	.	'<dt>Author:</dt>'	.	'<dd>'	.	$iteration["LoanAuthor"]	.	'</dd>';
				} # end, if, author

				# Due Date
				if (isset($iteration["DueDate"])) {
					$theHtml	=	$theHtml	.	'<dt>Due Date:</dt>'	.	'<dd>'	.	$iteration["DueDate"]	.	'</dd>';
				} # end, due date

				# Item Type
				if (isset($iteration["DocumentType"])	&&	($iteration["DocumentType"]	!=	"") )	{
					$theHtml	=	$theHtml	.	'<dt>Item Type:</dt>'	.	'<dd>'	.	$iteration["DocumentType"]	.	'</dd>';
				} # end item type
				
				# end the definition list
				$theHtml	=	$theHtml	.	'</dl>';

			break; # out

			case "pend":
			# Pending items.  Pretty much any item in any other status
				if ($count	==	0)	{ 
				# Mark the group if first time
					if ($jORb	==	"j")	{
						$theHtml	=	$theHtml	.	"<H2>Pending Journal Requests</H2>"; 
					} # end, if j
					else {
						$theHtml	=	$theHtml	.	"<H2>Pending Books Requests</H2>"; 
					} # end else book
					$count++;
				} # end count 0

				# Stuff item into a definition list
				$theHtml	=	$theHtml	.	'<dl>';
		
				# Transaction Number, URLified to Illiad to get whole status
				$theHtml	=	$theHtml	.	'<dt>Transaction number:</dt> '	.	'<dd>'	.
							'<a href="'	.	$this::STATUSURL	.	$iteration["TransactionNumber"] 
							.	'">'	.	$iteration["TransactionNumber"]	.	'</a>'	.	'</dd>';

				# Title, $typeIndex set above from $jORb
				$theHtml	=	$theHtml	.	'<dt>Title:</dt>'	.	'<dd>'	.	$iteration[$typeIndex]	.	'</dd>';

				# If a journal, article title
				if ($jORb	==	"j")	{
				# if a journal, add one more field, article title
					$theHtml	=	$theHtml	.	'<dt>Article:</dt>'	.	'<dd>'	.	$iteration["PhotoArticleTitle"]	.	'</dd>';
				} # end, if journal

				# Status if pending
				$theHtml	=	$theHtml	.	'<dt>Status:</dt>'	.	'<dd>'	.	$iteration["TransactionStatus"]	.	'</dd>';

				if ($jORb	==	"j")	{
				# if a journal, add one more field, article title
					$theHtml	=	$theHtml	.	'<dt>Article:</dt>'	.	'<dd>'	.	$iteration["PhotoArticleTitle"]	.	'</dd>';
					
					if (isset($iteration["PhotoArticleAuthor"])	&&	($iteration["PhotoArticleAuthor"]	!=	"") )	{
						$theHtml	=	$theHtml	.	'<dt>Author:</dt>'	.	'<dd>'	.	$iteration["PhotoArticleAuthor"]	.	'</dd>';
					} # end, if, author
					
				} # end, if journal
				
				else {
					if (isset($iteration["LoanAuthor"])	&&	($iteration["LoanAuthor"]	!=	"") )	{
						$theHtml	=	$theHtml	.	'<dt>Author:</dt>'	.	'<dd>'	.	$iteration["LoanAuthor"]	.	'</dd>';
					} # end, if, author
					
				} # end, else book

				# Item Type
				if (isset($iteration["DocumentType"])	&&	($iteration["DocumentType"]	!=	"") )	{
					$theHtml	=	$theHtml	.	'<dt>Item Type:</dt>'	.	'<dd>'	.	$iteration["DocumentType"]	.	'</dd>';
				} # end, documenttype
				
				# end the definition list
				$theHtml = $theHtml . '</dl>';

			break; # pend

			case "end":
			# Request finished status.  Books that are returned, articles that were on the web now expired.  
				if ($count == 0) { 
				# Mark the group if first time
				
					if ($jORb == "j") {
						$theHtml = $theHtml . "<H2>Past Journal Requests Completed</H2>"; 
					} # end, if j
				
					else {
						$theHtml = $theHtml . "<H2>Past BooksRequests Completed </H2>"; 
					} # else, b 
					
					$count++;
				
				} # end, count 
	
				# Stuff item into a definition list
				$theHtml	=	$theHtml	.	'<dl>';
	
				# Transaction Number, URLified to Illiad to get whole status
				$theHtml	=	$theHtml	.	'<dt>Transaction number:</dt> '	.	'<dd>'	.
								'<a href="' . $this::STATUSURL	.	$iteration["TransactionNumber"]	.
								'">'   . $iteration["TransactionNumber"]	.	'</a>'	.	'</dd>';

				# Title, $typeIndex set above from $jORb
				$theHtml	=	$theHtml	.	'<dt>Title:</dt>'	.	'<dd>'	.	$iteration[$typeIndex]	.	'</dd>';

				# If a journal, article title
				if ($jORb	==	"j")	{
				# if a journal, add one more field, article title
					$theHtml	=	$theHtml	.	'<dt>Article:</dt>'	.	'<dd>'	.	$iteration["PhotoArticleTitle"]	.	'</dd>';
				
				if (isset($iteration["PhotoArticleAuthor"])	&&	($iteration["PhotoArticleAuthor"]	!=	"") )	{
						$theHtml	=	$theHtml	.	'<dt>Author:</dt>'	.	'<dd>'	.	$iteration["PhotoArticleAuthor"]	.	'</dd>';
					} # end, if, author

				} # end, if journal
				
				else	{
					if (isset($iteration["LoanAuthor"])	&&	($iteration["LoanAuthor"]	!=	"") )	{
						$theHtml	=	$theHtml	.	'<dt>Author:</dt>'	.	'<dd>'	.	$iteration["LoanAuthor"]	.	'</dd>';
					} # end, if author
					
				} # end, else book

				# Due Date
				if ((isset($iteration["DueDate"]))	&&	($iteration["DueDate"]	!=	""))	{
					$theHtml	=	$theHtml	.	'<dt>Due Date:</dt>'	.	'<dd>'	.	$iteration["DueDate"]	.	'</dd>';
				} # end due date 

				# Item Type
				if (isset($iteration["DocumentType"])	&&	($iteration["DocumentType"]	!=	"") )	{
					$theHtml	=	$theHtml	.	'<dt>Item Type:</dt>'	.	'<dd>'	.	$iteration["DocumentType"]	.	'</dd>';
				} # end item type 

				# end the definition list
				$theHtml	=	$theHtml	.	'</dl>';

			break; # end 

			default:
				error_log ("Illiad replacement: pretty_print() reached unknown status case $index.");
				$this->mail_error("Illiad replacement: pretty_print() reached unknown status case $index.");
		
		} #end of switch
		
	} # end of foreach status

	$theHtml	=	$theHtml	.	"</div>";  # close up the divs we created above


	# Send back the HTML
	return $theHtml;	

} # end, prettyPrint

public function populateValues($typeFlags)	{
	#  Stuffs values on desired status types into  two global object members, books and journals. 
	# Parameters
	# 	$typeFlags	:	binary flags to signal what transaction types to populate, see constants in mail class info for definitions
	# 					To use them, OR them together, then we use AND to see what was set.  
	# Returns:
	#	True or False, depending on success
	#
	# Global Object Members modified:
	#	$books:		Populates the status subsections selected by typeFlags
	#	$journals:	Populates the status subsections selected by typeFlags
	#
	# How the searches are done:
	#	All filters are done via ODATA filters.
	#	The initial json URL is set in the constructor, taking the $illiadclass2::JSONBASE, appended with the userid
	#	To get just the types we want, we append "?$filter=TransactioStatus eq"and a transaction status, such as 'Request Finished'
	#
	# Internal  Variables 
	$count			=	 -1;
	
	# Web services temp variables
	$httpOpts		=	NULL;				# Options for HTTP header, including the APIKEY
	$context		=	NULL;				# Context for web services
	$items			=	NULL;				# Holds the JSON object retrieved from the call.  
	$tempURL		=	$this->jsonURL;		# Used for making the URLs to search with
	
	# For UTSA, any status not matching the searches above was decided to be "pending", so sPEND is the negation of all of the above
	$sPEND		=	"not%20(("		.	$this::sCANCEL	.		")%20or%20"	.	
												$this::sOUT  	.	"%20or%20"	.
												$this::sEND		. 	"%20or%20"	.
												$this::sWEB		.	")";

	# Set the http header.  
	$httpOpts		=	array(
								'http' => array(
											'method'	=>	"GET",
											'header'	=>	$this::APIKEY
								) # end, http array
						); # end, httpOpts

	# Do we want books?
	if  ($typeFlags	&	$this::bBOOKS)	{
		# We want Books	
			if ( $this::bPEND	&	$this::bCANCEL	&	$this::bEND	&	$this::bOUT	==	0)	{
				# Nothing to do? Error.
				error_log ("Illiad Replacement:  populateValues, with BOOK, without PEND, CANCEL, END, or OUT flags used");
				return($this::NONE);	
			} # end, do we have anything to do
			
				else {
					# We have books to fullfill

					if ($typeFlags	&	$this::bCANCEL)	{
						# Cancelled items
						# Take the URL we built in the constructor, and add the search parameters
						# For cancelled items, searching these two statuses
						#	-	Cancelled by ILL Staff
						# 	-	Cancelled by Customer
						$tempURL 		=	$this->jsonURL	.	"?\$filter="	.	$this::sCANCEL	.	$this::sBOOKS;
						$context		=	NULL;	# clean $context in case it's been used
						$items			=	NULL;	# clean $items in case it's been used	
						$count			=	-1;		# clean the count					

						# Access web services, fill the $items variable with the JSON object
						$context		=	stream_context_create($httpOpts);
						$items			=	file_get_contents($tempURL, FALSE, $context);

						# Verify file_get_contents worked correctly
						if ($items	===	FALSE)	{
							error_log("Illiad Replacement: populateValues, file_get_contents failed on BOOK and CANCEL");
							$this->mail_error("Illiad Replacement: populateValues, file_get_contents failed on BOOK and CANCEL");
							return($this::ERROR);
						} # end, if, file_get_contents items
						
						foreach(json_decode($items, TRUE)	as	$request)	{
							# parse the JSON object for the desired items
							$this->debug('Cancelled Book!'	.	$request["LoanTitle"]	.	'\n<p>');
		
							$count++;	# increase the array subscript.  
							$this->books["cancel"][$count]["TransactionNumber"]	=	$request["TransactionNumber"];
							$this->books["cancel"][$count]["tURL"]				=	$this::STATUSURL	.	$request["TransactionNumber"];
							$this->books["cancel"][$count]["TransactionStatus"] =	$request["TransactionStatus"];

							# Cancelled items shouldn't have a due date, but just in 						
							$this->books["cancel"][$count]["DueDate"]			=	$request["DueDate"];
							$this->books["cancel"][$count]["LoanTitle"]			=	$request["LoanTitle"];

							# Note, Author CAN be blank
							$this->books["cancel"][$count]["LoanAuthor"]		=	$request["LoanAuthor"];

							# Note, sometimes the type can be a bit decieving  (CDs or DVDs can be books instead of CD or DVD, etc0
							$this->books["cancel"][$count]["DocumentType"]		=	$request["DocumentType"];

							# For cancel only, include Reason
							$this->books["cancel"][$count]["ReasonForCancellation"]	=	$request["ReasonForCancellation"];
							
						} # end, json_decode
						
					} #end, cancel, books 

					if ($typeFlags	&	$this::bOUT)	{
						# Checked out items
						# Take the URL we built in the constructor, and add the search parameters
						# For out items, searching this statuses
						#	-	Checked Out to Customer
						$tempURL	 	=  	$this->jsonURL	.	"?\$filter="	.	$this::sOUT	.	$this::sBOOKS;
						$context		=	NULL;	# clean $context in case it's been used
						$items			=	NULL;	# clean $items in case it's been used						
						$count			=	-1;		# clean the count

						# Access web services, fill the $items variable with the JSON object
						$context		=	stream_context_create($httpOpts);
						$items			=	file_get_contents($tempURL, FALSE, $context);

						# Verify file_get_contents worked correctly
						if ($items	===	FALSE)	{
							error_log("Illiad Replacement: populateValues, file_get_contents failed on BOOK and OUT");
							$this->mail_error("Illiad Replacement: populateValues, file_get_contents failed on BOOK and OUT");
							return($this::ERROR);
						} # end, if, file_get_contents items

						
						foreach(json_decode($items, TRUE) as $request) {
							# parse the JSON object for the desired items
							$this->debug('Out Book!'	.	$request["LoanTitle"]	.	'\n<p>');
							$count++;

							$this->books["out"][$count]["TransactionNumber"]	=	$request["TransactionNumber"];
							$this->books["out"][$count]["tURL"]					= 	$this::STATUSURL	.	$request["TransactionNumber"];
							$this->books["out"][$count]["TransactionStatus"]	=	$request["TransactionStatus"];
							$this->books["out"][$count]["LoanTitle"]			=	$request["LoanTitle"];

							# Note, Author CAN be blank
							$this->books["out"][$count]["LoanAuthor"]    		=	$request["LoanAuthor"];

							# due date format is the day, the letter T, and a time, almost always 00:00:00.  Only the date is relevant to us, so the preg_replace strips 
							$this->books["out"][$count]["DueDate"]				=	date("M j, Y",strtotime(preg_replace("/T(.*)/","", $request["DueDate"])));

							# Note, sometimes the type can be a bit decieving  (CDs or DVDs can be books instead of CD or DVD, etc0
							$this->books["out"][$count]["DocumentType"]    		=	$request["DocumentType"];

							# For books that are ready for pickup, pickup location
							$this->books["out"][$count]["Location"]				=	$request["ItemInfo1"];

						} # end, json_decode
					} #end, out, books 

					if ($typeFlags	&	$this::bEND)	{
						# A book that's been returned, for history only
						# Take the URL we built in the constructor, and add the search parameters
						#	-	Request Finished
						$tempURL 		=  	$this->jsonURL	.	"?\$filter="	.	$this::sEND	.	$this::sBOOKS;
						$context		=	NULL;	# clean $context in case it's been used
						$items			=	NULL;	# clean $items in case it's been used						
						$count			=	-1;		# clean the count

						# Access web services, fill the $items variable with the JSON object
						$context		=	stream_context_create($httpOpts);
						$items			=	file_get_contents($tempURL, FALSE, $context);

						# Verify file_get_contents worked correctly
						if ($items	===	FALSE) {
							error_log("Illiad Replacement: populateValues, file_get_contents failed on BOOK and END");
							$this->mail_error("Illiad Replacement: populateValues, file_get_contents failed on BOOK and END");
							return($this::ERROR);
						} # end, if, file_get_contents items
						
						foreach(json_decode($items, TRUE) as $request) {
							# parse the JSON object for the desired items
							$this->debug('end Book!'	.	$request["LoanTitle"]	.	'\n<p>');
							$count++;
							$this->books["end"][$count]["TransactionNumber"] 	=	$request["TransactionNumber"];
							$this->books["end"][$count]["TransactionStatus"] 	=	$request["TransactionStatus"];
							$this->books["end"][$count]["LoanTitle"]			=	$request["LoanTitle"];

							# Note, Author CAN be blank
							$this->books["end"][$count]["LoanAuthor"]    		=	$request["LoanAuthor"];

							# Note, sometimes the type can be a bit deceiving (CDs or DVDs can be books instead of CD or DVD, etc
							$this->books["end"][$count]["DocumentType"]    		=	$request["DocumentType"];

						} # end, json_decode
					} #end, end, books 

					if ($typeFlags & $this::bPEND) {
					# Pending items
						# Take the URL we built in the constructor, and add the search parameters
						# For UTSA, it was determined that all statuses not falling into the other categories can be called "Pending"

						$tempURL	 	=  	$this->jsonURL	.	"?\$filter="	.	$sPEND	.	$this::sBOOKS;
						$context		=	NULL;	# clean $context in case it's been used
						$items			=	NULL;	# clean $items in case it's been used						
						$count			=	-1;		# clean the count

						# Access web services, fill the $items variable with the JSON object
						$context		=	stream_context_create($httpOpts);
						$items			=	file_get_contents($tempURL, FALSE, $context);

						# Verify file_get_contents worked correctly
						if ($items	===	FALSE)	{
							error_log("Illiad Replacement: populateValues, file_get_contents failed on BOOK and PEND");
							$this->mail_error("Illiad Replacement: populateValues, file_get_contents failed on BOOK and PEND");
							return($this::ERROR);
						} # end,  if, file_get_contents items

						foreach(json_decode($items, TRUE) as $request) {
							# parse the JSON object for the desired items
							$this->debug('Pending Book!'	.	$request["LoanTitle"]	.	'\n<p>');

							$count++;	# increase the array subscript.  
							$this->books["pend"][$count]["TransactionNumber"]	=	$request["TransactionNumber"];
							$this->books["pend"][$count]["tURL"]				=	$this::STATUSURL	.	$request["TransactionNumber"];
							$this->books["pend"][$count]["TransactionStatus"] 	=	$request["TransactionStatus"];
							$this->books["pend"][$count]["LoanTitle"]			=	$request["LoanTitle"];

							# Note, Author CAN be blank
							$this->books["pend"][$count]["LoanAuthor"]			=	$request["LoanAuthor"];

							# Note, sometimes the type can be a bit decieving  (CDs or DVDs can be books instead of CD or DVD, etc0
							$this->books["pend"][$count]["DocumentType"]		=	$request["DocumentType"];

						} # end, json_decode
						
					} #end, pend, books 
					
				} # end, else recognize status types
				
		}  # end, book flag

#						#
#^^^^^^^^^BOOKS^^^^^^^^^#
#########################
#\/\/\/\/JOURNALS\/\/\/\#
#						#

		if ($typeFlags & $this::bJOURNALS) {
		# We want Journals

			if ( $this::bPEND & $this::bCANCEL & $this::bEND & $this::bWEB	==	0) {
				# Nothing to do? Error.
					error_log ("Illiad Replacement:  populateValues, with JOURNAL, without PEND, CANCEL, END, or WEB  flags used");
					return($this::NONE);	
			} # end, do we have anything to do
			else {
			# We have journals to fullfill 
					if ($typeFlags & $this::bCANCEL) {
					# Cancelled items
						# Take the URL we built in the constructor, and add the search parameters
						# For cancelled items, searching these two statuses
						#	-	Cancelled by ILL Staff
						# 	-	Cancelled by Customer
						$tempURL 	=  	$this->jsonURL . "?\$filter=" . $this::sCANCEL .  $this::sJOURNALS;
						$context	=	NULL;	# clean $context in case it's been used
						$items		=	NULL;	# clean $items in case it's been used	
						$count		=	-1;		# clean the count					

						# Access web services, fill the $items variable with the JSON object
						$context	=	stream_context_create($httpOpts);
						$items		=	file_get_contents($tempURL, FALSE, $context);

						# Verify file_get_contents worked correctly
						if ($items === FALSE) {
							error_log("Illiad Replacement: populateValues, file_get_contents failed on JOURNAL and CANCEL");
							$this->mail_error("Illiad Replacement: populateValues, file_get_contents failed on JOURNAL and CANCEL");
							return($this::ERROR);
						} # end,  if, file_get_contents items
						
						foreach(json_decode($items, TRUE) as $request) {
							# parse the JSON object for the desired items
							$this->debug('Cancelled Journal!' .  $request["PhotoJournalTitle"] . '\n<p>');
		
							$count++;	# increase the array subscript.  
							$this->journals["cancel"][$count]["TransactionNumber"] 		=	$request["TransactionNumber"];
							$this->journals["cancel"][$count]["tURL"]					=	$this::STATUSURL . $request["TransactionNumber"];
							$this->journals["cancel"][$count]["TransactionStatus"] 		=	$request["TransactionStatus"];
							$this->journals["cancel"][$count]["PhotoJournalTitle"] 		=	$request["PhotoJournalTitle"];
							$this->journals["cancel"][$count]["PhotoArticleTitle"] 		=	$request["PhotoArticleTitle"];

							# NOTE: Author may be blank
							$this->journals["cancel"][$count]["PhotoArticleAuthor"] 	=	$request["PhotoArticleAuthor"];

							# Note, sometimes the type can be a bit decieving  (CDs or DVDs can be books instead of CD or DVD, etc0
							$this->journals["cancel"][$count]["DocumentType"]    		=	$request["DocumentType"];

							# For cancel only, include Reason
							$this->journals["cancel"][$count]["ReasonForCancellation"]	=	$request["ReasonForCancellation"];

						} # end, json_decode
					} #end, cancel, journals

					if ($typeFlags	&	$this::bEND)	{
						# A book that's been returned, for history only
						# Take the URL we built in the constructor, and add the search parameters
						#	-	Request Finished
						$tempURL 	=  	$this->jsonURL . "?\$filter=" . $this::sEND .  $this::sJOURNALS;
						$context	=	NULL;	# clean $context in case it's been used
						$items		=	NULL;	# clean $items in case it's been used						
						$count		=	-1;		# clean the count					

						# Access web services, fill the $items variable with the JSON object
						$context	=	stream_context_create($httpOpts);
						$items		=	file_get_contents($tempURL, FALSE, $context);

						# Verify file_get_contents worked correctly
						if ($items	===	FALSE)	{
							error_log("Illiad Replacement: populateValues, file_get_contents failed on JOURNAL and END");
							$this->mail_error("Illiad Replacement: populateValues, file_get_contents failed on JOURNAL and END");
							return($this::ERROR);
						} # end,  if, file_get_contents items
						
						foreach(json_decode($items, TRUE)	as	$request)	{
							# parse the JSON object for the desired items
							$this->debug('end journal!' .  $request["PhotoJournalTitle"] . '\n<p>');
							
							$count++;
							$this->journals["end"][$count]["TransactionNumber"] 	=	$request["TransactionNumber"];
							$this->journals["end"][$count]["tURL"]					=	$this::STATUSURL	.	$request["TransactionNumber"];
							$this->journals["end"][$count]["TransactionStatus"]		=	$request["TransactionStatus"];
							$this->journals["end"][$count]["PhotoJournalTitle"]		=	$request["PhotoJournalTitle"];
							$this->journals["end"][$count]["PhotoArticleTitle"]		=	$request["PhotoArticleTitle"];

							# NOTE: Author may be blank
							$this->journals["end"][$count]["PhotoArticleAuthor"] 	=	$request["PhotoArticleAuthor"];

							# Note, sometimes the type can be a bit decieving  (CDs or DVDs can be books instead of CD or DVD, etc0
							$this->journals["end"][$count]["DocumentType"]    		=	$request["DocumentType"];

						} # end, json_decode
						
					} #end, end, journals 

					if ($typeFlags	&	$this::bPEND)	{
						# Pending items
						# Take the URL we built in the constructor, and add the search parameters
						# For UTSA, it was determined that all statuses not falling into the other categories can be called "Pending"
						$tempURL 	=  	$this->jsonURL	.	"?\$filter="	.	$sPEND	.	$this::sJOURNALS;
						$context	=	NULL;	# clean $context in case it's been used
						$items		=	NULL;	# clean $items in case it's been used						
						$count		=	-1;		# clean the count	

						# Access web services, fill the $items variable with the JSON object
						$context	=	stream_context_create($httpOpts);
						$items		=	file_get_contents($tempURL, FALSE, $context);

						# Verify file_get_contents worked correctly
						if ($items	===	FALSE)	{
							error_log("Illiad Replacement: populateValues, file_get_contents failed on JOURNAL and PEND");
							$this->mail_error("Illiad Replacement: populateValues, file_get_contents failed on JOURNAL and PEND");
							return($this::ERROR);
						} # end,  if, file_get_contents items

						foreach(json_decode($items, TRUE)	as	$request)	{
							# parse the JSON object for the desired items
							$this->debug('Pending Journal!' .  $request["PhotoJournalTitle"] . '\n<p>');

							$count++;	# increase the array subscript.  
							$this->journals["pend"][$count]["TransactionNumber"]	=	$request["TransactionNumber"];
							$this->journals["pend"][$count]["tURL"]					=	$this::STATUSURL . $request["TransactionNumber"];
							$this->journals["pend"][$count]["TransactionStatus"]	=	$request["TransactionStatus"];
							$this->journals["pend"][$count]["PhotoJournalTitle"]	=	$request["PhotoJournalTitle"];
							$this->journals["pend"][$count]["PhotoArticleTitle"] 	=	$request["PhotoArticleTitle"];

							# NOTE: Author may be blank
							$this->journals["pend"][$count]["PhotoArticleAuthor"] 	=	$request["PhotoArticleAuthor"];

							# Note, sometimes the type can be a bit deceiving (CDs or DVDs can be books instead of CD or DVD, etc0
							$this->journals["pend"][$count]["DocumentType"]    		=	$request["DocumentType"];

						} # end, json_decode
						
					} #end, pend, journals 

					if ($typeFlags	&	$this::bWEB)	{
						# Articles delivered to the web
						# Take the URL we built in the constructor, and add the search parameters
						# Status types:
						# - Delivered to Web
						$tempURL 	=  	$this->jsonURL	.	"?\$filter="	.	$this::sWEB	.	$this::sJOURNALS;
						$context	=	NULL;	# clean $context in case it's been used
						$items		=	NULL;	# clean $items in case it's been used						
						$count		=	-1;		# clean the count	

						# Access web services, fill the $items variable with the JSON object
						$context	=	stream_context_create($httpOpts);
						$items		=	file_get_contents($tempURL, FALSE, $context);

						# Verify file_get_contents worked correctly
						if ($items	===	FALSE)	{
							error_log("Illiad Replacement: populateValues, file_get_contents failed on JOURNAL and WEB");
							$this->mail_error("Illiad Replacement: populateValues, file_get_contents failed on JOURNAL and WEB");
							return($this::ERROR);
						} # end,	if, file_get_contents items

						foreach(json_decode($items, TRUE)	as	$request)	{
							# parse the JSON object for the desired items
							$this->debug('Delivered to Web Journal!'	.	$request["PhotoJournalTitle"]	.	'\n<p>');
		
							$count++;	# increase the array subscript.  
							$this->journals["web"][$count]["TransactionNumber"]	=	$request["TransactionNumber"];
							$this->journals["web"][$count]["tURL"]				=	$this::STATUSURL	.	$request["TransactionNumber"];
							$this->journals["web"][$count]["TransactionStatus"] =	$request["TransactionStatus"];
							$this->journals["web"][$count]["PhotoJournalTitle"]	=	$request["PhotoJournalTitle"];
							$this->journals["web"][$count]["PhotoArticleTitle"] =	$request["PhotoArticleTitle"];

							# NOTE: Author may be blank
							$this->journals["web"][$count]["PhotoArticleAuthor"]	=	$request["PhotoArticleAuthor"];

							# Note, sometimes the type can be a bit decieving  (CDs or DVDs can be books instead of CD or DVD, etc0
							$this->journals["web"][$count]["DocumentType"]    		=	$request["DocumentType"];

							# figure out expiration date of the delivered to web article
							$this->journals["web"][$count]["ExpirationDate"]		=	date("M j, Y", strtotime(preg_replace("/T(.*)/","", $request["TransactionDate"])  .  $this::EXPIRETIME));

							# URL just for journal articles
							$this->journals["web"][$count]["URL"]					=	$this::DELIVERURL	.	$request["TransactionNumber"] ;

						} # end, json_decode
						
					} #end, web, journals 

			} # end, else, valid journal status types
			
		} # end, journal flag

	return($this::GOOD);  

} # end, populateValues

private function validateUserId($id) {
	# Purpose: Validates that the userid we get passed in is in a valid format.
	# UTSA specific, based on 3 alpha characters and 3 numeric.  
	# OUTSIDE UTSA? Replace this function with your own logic to verify a correct format and valid userid
	#
	# Parameters:
	# 	$id		:	userid to validate, at UTSA, it's an abc123 via shibboleth
	# 
	# Internal Variables
	# 	$ldap	:	Ldap connection, for verifying userid

	# First, make sure userid is in proper format
	if (preg_match("/^([a-z]{3}[0-9]{3})$/i", $id) != 1) {
		echo "ERROR: your ID doesn't appear to be a valid abc123\n<p>";
		return(FALSE);
	} # end, if, abc123 match
	
	else { 
		return(TRUE); 
	} # end, else

} #end, validateUserId

private function arrayInit() {
	# Arrayinitialization	(ensures can easily parse later.  use transaction number of -1 as flag of none of that type)
	# 	             		(If there is a transaction of the type, these will get overwritten).
	#
	# Parameters:
	# 	NONE, all set are object members.

	# Journal pend
	$this->journals["pend"][0]["TransactionNumber"]		= 	-1;
	$this->journals["pend"][0]["TransactionStatus"] 	=	NULL;
	$this->journals["pend"][0]["PhotoJournalTitle"] 	=	NULL;
	$this->journals["pend"][0]["tURL"] 					=	NULL;
	$this->journals["pend"][0]["DocumentType"] 			=	NULL;
	$this->journals["pend"][0]["PhotoArticleTitle"] 	=	NULL;
	$this->journals["pend"][0]["PhotoArticleAuthor"]	=	NULL;

	# Journal cancel
	$this->journals["cancel"][0]["TransactionNumber"] 		=	-1;
	$this->journals["cancel"][0]["TransactionStatus"]		=	NULL;
	$this->journals["cancel"][0]["PhotoJournalTitle"]    	=	NULL;
	$this->journals["cancel"][0]["ReasonForCancellation"]	=	NULL;
	$this->journals["cancel"][0]["tURL"] 					=	NULL;
	$this->journals["cancel"][0]["DocumentType"] 			=	NULL;
	$this->journals["cancel"][0]["PhotoArticleTitle"] 		=	NULL;
	$this->journals["cancel"][0]["PhotoArticleAuthor"]		=	NULL;


	# Journal end
	$this->journals["end"][0]["TransactionNumber"]			=	-1;
	$this->journals["end"][0]["TransactionStatus"]			=	NULL;
	$this->journals["end"][0]["PhotoJournalTitle"]			=	NULL;
	$this->journals["end"][0]["tURL"] 						=	NULL;
	$this->journals["end"][0]["DocumentType"] 				=	NULL;
	$this->journals["end"][0]["PhotoArticleTitle"] 			=	NULL;
	$this->journals["end"][0]["PhotoArticleAuthor"]			=	NULL;


	# Journal web
	$this->journals["web"][0]["TransactionNumber"] 			=	-1;
	$this->journals["web"][0]["TransactionStatus"]			=	NULL;
	$this->journals["web"][0]["PhotoJournalTitle"]			=	NULL;
	$this->journals["web"][0]["URL"]						=	NULL;
	$this->journals["web"][0]["tURL"] 						=	NULL;
	$this->journals["web"][0]["DocumentType"] 				=	NULL;
	$this->journals["web"][0]["PhotoArticleTitle"] 			=	NULL;
	$this->journals["web"][0]["PhotoArticleAuthor"]			=	NULL;
	$this->journals["web"][0]["ExpirationDate"]				=	NULL;


	# Book pend
	$this->books["pend"][0]["TransactionNumber"]			=	-1;
	$this->books["pend"][0]["TransactionStatus"]			=	NULL;
	$this->books["pend"][0]["LoanTitle"]					=	NULL;
	$this->books["pend"][0]["tURL"] 						=	NULL;
	$this->books["pend"][0]["DocumentType"] 				=	NULL;
	$this->books["pend"][0]["LoanAuthor"] 					=	NULL;

	# Book cancel
	$this->books["cancel"][0]["TransactionNumber"] 			=	-1;
	$this->books["cancel"][0]["TransactionStatus"]			=	NULL;
	$this->books["cancel"][0]["LoanTitle"]					=	NULL;
	$this->books["cancel"][0]["ReasonForCancellation"]		=	NULL;
	$this->books["cancel"][0]["tURL"] 						=	NULL;
	$this->books["cancel"][0]["DocumentType"] 				=	NULL;
	$this->books["cancel"][0]["LoanAuthor"] 				=	NULL;

	# Book end
	$this->books["end"][0]["TransactionNumber"]				=	-1;
	$this->books["end"][0]["TransactionStatus"]				=	NULL;
	$this->books["end"][0]["LoanTitle"]						=	NULL;
	$this->books["end"][0]["tURL"]	 						=	NULL;
	$this->books["end"][0]["DocumentType"] 					=	NULL;
	$this->books["end"][0]["LoanAuthor"] 					=	NULL;

	# Book out
	$this->books["out"][0]["TransactionNumber"]				=	-1;
	$this->books["out"][0]["TransactionStatus"]				=	NULL;
	$this->books["out"][0]["LoanTitle"]						=	NULL;
	$this->books["out"][0]["Location"]						=	NULL;
	$this->books["out"][0]["tURL"] 							=	NULL;
	$this->books["out"][0]["DocumentType"] 					=	NULL;
	$this->books["out"][0]["DueDate"]		 				=	NULL;
	$this->books["out"][0]["LoanAuthor"] 					=	NULL;

} # end, arrayInit

##########
# MISC UTILITY FUNCTIONS
##########
	private function mail_error($msg) {
	# used to notify systems if unexpected behaviour happens during regular use so they can be investigated
	# Most errors also sent to apache error log, but mailing the most serious/unexplained, so they can 
	# be sure to be researched
		mail ($this::ERRORMAIL, "Illiad Substitute Error message,illiadclass2.php\n", "This error message came from the Illiad Substitute page\n" . $msg .
			"\n REFERER:" .  $_SERVER['HTTP_REFERER'] 	. "\n USER AGENT: " . $_SERVER['HTTP_USER_AGENT']	
		);
} #end of mail_errors
	
	private function debug($string) {
	# used for debugging statements throughout the script, set global $DEBUG to true to have them print
	# In production, set $DEBUG to false.
	# flag to display lots of debugging messages
	$DEBUG     	= FALSE; 

	if ($DEBUG) {
		echo  "DEBUG:" . $string . "<p>\n";
	} #end of if
} #end of debug

} # end, class
?>
