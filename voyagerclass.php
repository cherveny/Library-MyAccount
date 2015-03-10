<?php
#	include "xml2Arrayclass.php";   Depricated.  Now using 

class voyagerclass {
# My Account Replacement: Voyager class
# Coded by Bruce A Orcutt, UTSA.
# Bruce.orcutt@utsa.edu (210) 458-6192
#  Code designed to connect to ExLibris Voyager, extract basic information via the provided Restful web services, and give a "pretty print" method for displaying the data
# Also requires accessing the backend Oracle database to extract the patronID, as we only get the "abc123" id from shibboleth (UTSA).
# From the database we also extract the SSAN, Firstname and Lastname, if needed.  
# If distributing  outside of UTSA, be sure to edit the constants below, to reflect your own installation.
# Outside UTSA, please remove all userids and passwords and hostnames
 

#  public members:
	public		$shibid				= 	NULL; 		# abc123 id from shibboleth
	public 		$fines 	  			= 	0.0;		# fines owed by the patron (float)
	public 		$lastName	  		= 	NULL; 		# patron last name
	public 		$firstName 			= 	NULL;		# patron first name
	public 		$SSAN 		   		= 	0; 			# SSAN for patron from database (int)
	public 		$patronID 			= 	NULL;		# patron bar code from voyager database
	public 		$patronLoans		= 	NULL;		# multidimensional array to hold all currently loaned items

# private members
	private		$dbKey				= 	NULL;		# Needed for building the proper URLs	
	private		$XMLConvert 		= 	NULL;	  	# Holds SimpleXML objects for parsing, temporary values
	private 	$patronCircURL		= 	NULL; 		# Holds restful URL to Patron Circulation info
	private 	$patronInfoURL		=	NULL;		# Not currently needed, but have in case additional info about patron needed in later upgrade

# Public Methods:
# 	constructor:		pre-populates all public and private values listed above
#	prettyPrint:		for displaying values populated above.

#
# Constants
#
# severe errors are emailed, usually should be set to systems group	
	const	ERRORMAIL			=	"XXXX.YYYY@ABC.EDU";
	
# provides base for all web services access
# Outside UTSA, replace hostname with proper hostname, and XXXX with webservices port
	const	WEBSRVDBINFO 		=	"http://opac.host.goes.here.edu:XXXX/vxws/dbInfo?option=dbinfo"; 
	const	WEBSRVPATRON		=	"http://opac.host.goes.here.edu:XXXX/vxws/patron/";

# Voyager Database string
# Outside UTSA remove hostname and SID and replace with your own. XXXX is database port, usually 1521 for Oracle
	const	VOYDB				=	"(DESCRIPTION=(ADDRESS_LIST = (ADDRESS = (PROTOCOL = TCP)(HOST = database.host.goes.here.edu)(PORT = XXXX)))(CONNECT_DATA=(SID=YOURSIDHERE)))" ;

# Voyager read only database password
# Outside UTSA remove password, use a read only account database password
	const	VOYPASS				=	"ro_password";	
	
# Voyager read only database username
# Outside UTSA remove username, user a read only account database userid
	const	VOYUSER				=	"ro_userid";	
	
# Hostname for web services (used for replacing localhost 127.0.0.1 IP, if passed via XML
# Outside UTSA remove hostname, replace with your opac host name
	const	OPACHOST			=	"replace.with.your.opac.host.edu";


# Patern for mathcing the hostname in URLs.
# At UTSA, all the restful URLs are returned with 127.0.0.1, instead of the desired hostname
	const	HOSTPATTERN		=	"/127.0.0.1/";

# URL used to process a renewal request
# Outside UTSA, remove hostname 
#  Uses $voyager->renew method, passed in the URL, with a passed in URL.  
	const	RENEWURL			=	"https://replace.with.your.web.host.edu/cgi-bin/MAR/voyager.renewal.php?renewal=";

# Binary flags for prettyPrint, for chosing fields to print (0b notation unavailable, >PHP5.4 only.  webapp has php 5.3
	const 	bFINE		=	1; 			# 0b00000001;		# print You have fines! You owe: $X
	const 	bTITLE		=	2;			# 0b00000010;		# include the title of the item.
	const 	bDUEDATE	=	4;			# 0b00000100;		# include when the item is due
	const	bITEMTYPE	=	8; 			# 0b00001000;		# include the item type (book, dvd, blueray, etc)
	const	bSTATUS		=	16; 		# 0b00010000;		# include the item status (overdue, renewed, checked out, etc)
	const	bCALLNUMBER	=	32;			# 0b00100000;		# include the item number
	const 	bRENEWAL	=	64;			# 0b01000000;		# include if the item can be renewed, including a link to click to renw the 
	
public function prettyPrint($flags)	{
	# Method to print items currently on loan
	# Parameters:
	#	flags	:	What fields to print, see constants for bitmasks

	# Internal Variables
	$iteration	=	NULL;		# For traversing the loans

	$this->debug("IN prettyPrint");

	# First, make sure flags have been passed in.
	if($flags	===	0)	{
		# Nothing to do
		return (FALSE);
	} # end, no flags 

	# Start the HTML, put all the voyager items within a div
	echo "<div class='voyager-items'>";

	##FINES
	
	# Do we want to print fines?
	if  ($flags	&	$this::bFINE)	{
		echo "<div class='fines'>";

		if (($this->fines)	>	0 )	{
			echo "<H3>You have fines!</H3><br />\n";
			echo "You owe: $"	.	$this->fines	.	"<br /> \n"; 
		} # end, if fines	

		else {
			echo "<H3>You  currently have no fines.</H3><br />\n";
		} # end, else no fines

	} # end, bFINE

	###END FINES

	###ITEMS

	if ($flags	>	$this::bFINE)	{
		# do we have anything to do outside of fines?

		# Put everything into a definition list
		echo "<dl>";

		foreach ($this->patronLoans["loans"]	as	$iteration) {
			# cycle through all the loans, display fields based on binary flags passed in.
		
			# create a div for each loaned item
			echo "<div class='voyager-item'>"; 

			# Title
			if  ($flags	&	$this::bTITLE)	{
				echo "<dt>Title</dt>";			
				echo "<dd>"	.	$iteration["title"]	.	"</dd>";
			} # end, if title

			# Due Date
			if  ($flags	&	$this::bDUEDATE)	{
				echo "<dt>Due Date</dt>";			
				echo "<dd>"	.	$iteration["dueDate"]	.	"</dd>";
			} # end, if due date

			# Item Type
			if  ($flags	&	$this::bITEMTYPE)	{
				echo "<dt>Item Type</dt>";			
				echo "<dd>"	.	$iteration["itemtype"]	.	"</dd>";
			} # end, if item type

			# Item Status
			if  ($flags	&	$this::bSTATUS)	{
				echo "<dt>Status</dt>";			
				echo "<dd>"	.	$iteration["StatusText"]	.	"</dd>";
			} # end, if status

			# Call Number
			if  ($flags	&	$this::bCALLNUMBER)	{
				echo "<dt>Item Type</dt>";			
				echo "<dd>"	.	$iteration["callNumber"]	.	"</dd>";
			} # end, if call number

			# If can renew, give the link able to renew
			if($iteration["canRenew"]	==	"Y")	{
				echo "<dt>This item can be renewed!</dt>";
				$URL  = urlencode($iteration["URL"]);
				echo "<dd><a href='"	.	$this::RENEWURL	.	$URL	.	"&'> Renew this now!</a></dd>";
			} # end, if, canRenew

			echo "<hr />";  # line

		} # end, foreach loans
	
		# End the definition list for voyager items
		echo "</dl>";

	} # end, if flags

	###END ITEMS
	
	# end the div for all voyager items
	# Outside of the items loop, as may just want to print fines
	echo "</div>"; 

	$this->debug("OUT prettyPrint");

	return(TRUE);

} # end, prettyPrint



public function __construct($shibid) {
	# Creates a new voyagerclass object.  
	# Consturctor populates all public values stated above
	#
	# Parameters:
	#	sbibid	:	Valid abc123 format id from shibboleth.
	
	# Internal Varialbles
	$row			=	NULL;		# for voyager database queries.

	$this->shibid 	=	$shibid;	# Set the userid 

	$this->debug($this->shibid	.	" is the id<p>\n");

	# Validate id is an actual abc123 by validating vs regular expression
	# IF OUTSIDE UTSA, replace with logic verifying your own userid formats
	if (preg_match("/^([a-z]{3}[0-9]{3})$/i", $this->shibid)	!=	1)	{
		error_log("ID  "	.	$this->shibid	.	"is not an abc123, aborting!");
		$this->mail_error("The ID,  "	.	$this->shibid	.	" , returned by Shibboleth _SERVER(uid) is not a valid abc123"); 

		# Can't send a return code for a constructor.  so have to throw an exception
		throw new Exception("Your UserID appears to be invalid");
		
	} # end, if, preg_match

	# Database connection
	# Grab the login info from the database, based on Shibboleth set UID, stuff in variable $row
	$row	=	$this->dbLogon($shibid);

	# Check to see if dbLogon had an error
	if ($row	===	FALSE)	{
		# Can't send a return code for a constructor.  so have to throw an exception
		throw new Exception("Your UserID appears to be invalid");
	} # end, $row, FALSE
	
	else	{
		# set the variables based on database fetch above
		$this->SSAN			=	$row["SSAN"];
		$this->patronID		=	$row["PATRON_ID"];
		$this->lastName		=	$row["LAST_NAME"];
		$this->SfirstName	=	$row["FIRST_NAME"];
	} # end, else, $row

	#
	## End Database Connection

	# 
	## Get the DBKey

	# The DB key is required on all subsequent web services calls.  Requesting it, rather than hard coding helps insulate against future changes

	$this->debug("_-=Begin of DBKey=-_");

	# Query web services for the dbkey 
	$XMLConvert		=  	$this->get_webservice($this::WEBSRVDBINFO);

	# Check to see if we encountered errors
	if ($XMLConvert	===	FALSE)	{
		# Can't send a return code for a constructor.  so have to throw an exception
		throw new Exception("Error retrieving dbKey");
	} # end, if XMLConvert

	else {
		# set the dbKey if we didn't get an error
		$this->dbKey	=	 (string)$XMLConvert->dbInfo->dbKey;	# Cast REQUIRED
	} # end, else XMLConvert

	$this->debug("dbKey is "	.	$this->dbKey);
	$this->debug("_-=End of DBKEY=-_");

	#
	## END, DBKEY
	#

	#
	## GET PATRON RECORD
	
	# gets the $patronCircURL used by the CIRCULATION ACTIONS section

	# Reset variables 
	unset($iteration);
	$XMLConvert		=	NULL;

	$this->debug("_-=Begin of Patron=-_");

	# Exrract what we need from the XM, using the stub of WEBSRVPATRON (adding in retrieved dbkey and patron_id
	$XMLConvert 		=	$this->get_webservice($this::WEBSRVPATRON . $this->patronID. "?patron_homedb=1@" . $this->dbKey);

	# Check to see if we encountered errors
	if ($XMLConvert	===	FALSE)	{
		# Can't send a return code for a constructor.  so have to throw an exception
		throw new Exception("Error retrieving patron record");
	} # end, if XMLConvert

	else {
		# set the patron record, and more importantly, the URLs 
		$patronRecord	=	$this->get_patronRecord($XMLConvert);

		if ($patronRecord	===	FALSE)	{
			# Can't send a return code for a constructor.  so have to throw an exception
			throw new Exception("Error retrieving patron record2");
		}  # end, if, $patronRecord

		# Store the URLs.  Circ is the only one used at the moment.
		$this->patronCircURL	= 	$patronRecord["patronCircURL"];
		$this->patronInfoURL	= 	$patronRecord["patronInfoURL"];

	} # end, else XMLConvert

	$this->debug("\n<p>=======================\n<p>");
	$this->debug("XMLConvert is " .   $XMLConvert);
	$this->debug("PatronCircURL is " . $this->patronCircURL);
	$this->debug("PatronInfoURL is " . $this->patronInfoURL);

	$this->debug("_-=End of Patron=-_");

	#
	## END PATRON RECORD
	#

	# 
	## BEGIN CIRC ACTION
	
	# Reset variables
	$XMLConvert	=	NULL;

	$this->debug("_-=Begin of CIRC=-_");

	$XMLConvert			=	$this->get_webService($this->patronCircURL);
	
	# Make sure we got something back from get_webService
	if ($XMLConvert	===	FALSE) {
		# Can't send a return code for a constructor.  so have to throw an exception
		throw new Exception("Error retrieving patron record");
	} # end, if XMLConvert

	else {
		# we got the results, so parse them	
		$this->patronLoans		=	$this->get_circ($XMLConvert);
		
		# Verify we got back some loaned items
		if($this->patronLoans	===	FALSE) {
			# we are still in the constructor, so have to throw an exception, not a return code
			throw new Exception("Error retrieving patron loans");
		} # end, patronLoans FALSE
		else {
			$this->fines				=	$this->patronLoans["fines"];
		} #end else patronLoans is good

	} # end, else, good XMLConvert

	$this->debug("CIRC: PatronLoans is " .  $this->patronLoans);
	$this->debug("_-=End of CIRC=-_");

	# 
	## END CIRC ACTION
	#

} # end, constructor
	#############END of the CONSTRUCTOR


	######Voyager specific functions

private function dbLogon($uid) {
	# Purpose: retrueves a row from the voyager database, containing the users ssan, firstname, lastname and patron_id
	#
	# Returns:
	#		Associative array containing the following keys, with a string for each:  
	#			SSAN, 
	#			PATRON_ID, 
	#			LAST_NAME,
	#			FIRST_NAME
	#
	# Global Contstants required:
	#		VOYUSER		:		Read Only database user
	#		VOYPASS		:		Read Only database password
	#		VOYDB		:		SID for voyager database
	#
	# Parameters
	#		$uid			:		Set by shibboleth

	# Internal Variables
	$connection			=	NULL;		# Oracle connection string
	$statementHandle	=	NULL;		# oci parse result
	$row	     	 	=	NULL;		# holds returned results from Oracle
	$error	     	 	=	NULL;		# holds error messages, especially from OCI calls, to be used in error printing statements.

	$this->debug("--begin dbLogin");

	# Connect to the Voyager database
	$connection	=	oci_connect(voyagerclass::VOYUSER,voyagerclass::VOYPASS,voyagerclass::VOYDB);

	# Did it work?
	if (!$connection)	{
		$error	=	oci_error();
		error_log ("Voyager Replacement: Database Connection Error "	.	$error);
		$this->mail_error("Voyager Replacement: Database Connection Error "	.	$error);	
		return(FALSE);	
	} # end, if connection	

	# Create the statement handle for getting user's SSAN for login (used for authentication
	$statementHandle	=	oci_parse($connection, "select SSAN,FIRST_NAME,LAST_NAME,PATRON_ID from PATRON where INSTITUTION_ID = '$uid'");

	# Verify handle created
	if (!$statementHandle) {
		#if false, it failed
		$error = oci_error();
		error_log ("Voyager Replacement: Database Parse Error " . $error);
		$this->mail_error("Voyager Replacement: Database Parse Error " . $error);		
		return(FALSE);	
	} # end, if statementHandle

	# Get the result of the select statement
	if (!oci_execute($statementHandle)) {
	#if false, it failed
		$error = oci_error();
		error_log ("Voyager Replacement: Database Execute Error " . $error);
		$this->mail_error("Voyager Replacement: Database Execute Error " . $error);		
		return(FALSE);	
	} # end, if, oci_execute

	$row = oci_fetch_array($statementHandle, OCI_ASSOC);

	# Clean Up
	unset($statementHandle);
	oci_close($connection);

	$this->debug("---OUT dbLogon---");
	
	# Return the selected row	
	return($row);

} # end, dbLogin

private function get_webservice($url) {
	# Reuse code for grabbing XML from a webservice call
	#
	# Requires:
	#	$SimpleXML			:		(usually included by default in most PHP installations)
	#	$this->debug()		: 		prints $this->debug messages
	#	mail_error()		:		for serious errors, mails to systems.
	
	# Returns:
	#	$XMLConvert			:		A SimpleXMLElement object
	#	FALSE				:		If errors arrise, returns FALSE
	
	# Parameters:
	# 	$url:	The URL we are using for this specific web services request

	#
	## Internal Variables
	#
	$postParameters	=	NULL;		# parameters to send in web services request.
	$webStream		=	NULL;		# Web Services stream handle	
	$filePointer	=	NULL;		# for file open on web stream
	$response		=	NULL;		# returned XML from Voyager Web Services
	$iXMLConvert	=	NULL;		# SimpleXMLobject, used for converting XML to array
	$replyCode		=	NULL;		# For checking for success condition
	
	$this->debug("---IN get_webservice---");

	# Set the headers for the HTTP connection, used 
	$postParameters	=	array(
								'http' => array(
		              							'method' => 'GET'
					       	    		)
						); # end, postParameters
	
	# Make the connection
	$webStream		=	stream_context_create($postParameters);
	$filePointer	=	fopen($url, 'rb', FALSE, $webStream);

	$this->debug("URL is " . $url);
	$this->debug("filePointer is " .  $filePointer);
	$this->debug("webStream is " .  $webStream);

	# Check to make sure we got something back.	
	if (!$filePointer)	{
		$this->mail_error("Voyagerclass,php, Problem with $url, $php_errormsg");
		error_log("Voyagerclass.php, Problem with $url, $php_errormsg");
		return(FALSE);
	} #end, if filepointer

	$response = @stream_get_contents($filePointer);

	if ($response	===	FALSE)	{
		$this->mail_error("Voyagerclass.php, Problem reading data from $this::WEBSRVURL $url, $php_errormsg");
		error_log ("Voyagerclass.php, Problem reading data from $this::WEBSRVURL $url, $php_errormsg");
		return(FALSE);
	} #end, response 

	$this->debug(" response is " .  $response);

	# Exrract what we need from the XML 
	$iXMLConvert	=	new SimpleXMLElement($response);

	# Clean up and return
	fclose($filePointer);

	$this->debug("--OUT get_webservice--");

	return($iXMLConvert);

} # end, get_webService

private function get_patronRecord($XMLConvert)	{
	# Gets the two patron info URLs from web services
	
	# Returns
	#	 	Associative arraw:
	#				key patronCircURL	:	 For the URL that contains the circulation actions
	#				key patronInfoURL	:	 Patron info.  Currently not used, but saving in case needed for later additional functionality
	#		FALSE:
	#				Returns FALSE if an error is encountered
	
	# Pparameters:
	#				$XMLConvert		: 	SimpleXMLElement object, from web stream

	# Internal Variables
 	$row["patronInfoURL"]	=	NULL;	# The assoicative array we will return
 	$row["patronCircURL"]	=	NULL;	# The assoicative array we will return
	$iteration				=	NULL;	# For a foreach loop to parse the patron items

	$this->debug("--IN get_patronRecord");

	foreach ($XMLConvert->patron->info as $iteration) { 
	# Loop over the two URLs, assign the proper one to the proper variable

		$this->debug("g_PR: iteration is " .  (string)$iteration["type"]  . "\n");

		switch( $iteration["type"])	{

			case 'Circulation Actions':
				# Main thing we need for circulation actions below
				$row["patronCircURL"]	=	$this->fix_url((string) $iteration["href"]);  	# CAST Required

				$this->debug("g_PR:  " . $row["patronCircURL"]);

			break; # end, case Circulation Actions

			case 'Patron Information':
				# May not need, but grabbing it just in case need later.
				$row["patronInfoURL"]	=	$this->fix_url((string) $iteration["href"]);	# CAST Required
				
				$this->debug("g_PR:  " . $row["patronInfoURL"]);
					
			break; # end, case Patron Information

			default:
				# Notifies Systems, so we can update the code, as needed, for other unexpected cases
				# May be able to predict some via ExLibris documentation
				# If field not needed, user will notice no issue
				
				$this->debug("We got $response in patron info.  This was unexpected\n");
				$this->mail_error("Voyagerclass.php Unexpected item in patron info url XML, $response");
				error_log  ("Voyagerclass.php Unexpected item in patron info url XML, $response");
				
				return(FALSE);
		
		} # end, switch

	} # end, foreach

	if (($row["patronInfoURL"]	==	NULL)	||	($row["patronCircURL"]	==	NULL))	{
		# if we don't get the URLs, we have an unrecoverable error
		$this->mail_error	("Voyagerclass.php Unexpected item in patron info url XML, $response");
		error_log			("Voyagerclass.php Unexpected item in patron info url XML, $response");
		
		return(FALSE);
	
	} # end, if, URLs null

	$this->debug("g_PR: Row is " .  $row);
	$this->debug("--OUT get_patronRecord");

	return ($row);

} # end, get_patronRecord

private function fix_url($url)	{
	# URLs from UTSA's Voyager return as 127.0.0.1, this function fixes the URLs to be the proper hostname
	# replace contsants as required at your installation
	
	# requires contsants:
	# 	HOSTPATTERN:		Currently 127.0.0.1, set as a constant in case this changes in the future
	#	OPACHOST:			Proper hostname for the OPAC
	
	# Returns:
	#	String, fixed URL.
	
	# Parameters:
	#	$url:					String, URL needing HOSTPATTERN replaced with OPACHOST

	# Internal Variables
	$tempURL	=	NULL; # for manipulation

	$tempURL	=	preg_replace("/\|/","%7C",$url);  # URLs don't like | characters! And ExLibris sometimes puts em in!
	$tempURL	= 	preg_replace($this::HOSTPATTERN,$this::OPACHOST,$tempURL); 
	
	return($tempURL);
} # end, fix_url

private function get_circ($XMLConvert)	{
	# function to get all circulation related items
	# Unlike previous functions, this function parses the subURLs of it's XML members, instead of storing the URL and moving on.
	#	
	# Returns:
	# 	multi-dim array, containing all circulation related items
	#	 		$patronItems	["fines"]			:	int, Amount, in $US of fines owed.  0 if no current fines.
	#						 	["numLoans"]		:	int, how many items currently on loan
	#			$patronItems	["loans"]			:	items loaned, array
	#			$patronItems	["loans"]	{0]		:  	numerical index for each item, initialized to NULL, so if NULL, no items currently loaned
	#
	#	FALSE				:		Returns FALSE if an error is encountered
	#
	#  Parameters:
	#			$XMLConvert:					SimpleXMLElement Object, contains object based on Circulation Actions XML
	#  
	# Requires:
	#	debug():			For debugging messages
	#	SimpleXML:		Built into most PHP setups
	#	mail_error():		For sending severe errors for code corrections to Systems group
	# 	get_webservice():	For browsing sub URLs.  


	# Internal Variables
	$count						=	0;
	$iteration					=	NULL;		# for use in foreach loops
	$iteration2					=	NULL;		# for use in foreach loops
	$debtURL					=	NULL;		# holds the URL for retrieving any fines for the user
	$loansURL					=	NULL;		# holds the URL for retrieving any fines for the user
	$tempDebt					=	NULL;		# for holding string from parsing to fines
	$fineTemp					=	0;			# converstion
	$tempLoans					=	NULL;		# for holding object to parse individual loaned item URLs from
	
	# Object items set within this method 
	$patronItems["fines"]		=	0;			# holds user's fines, if any
	$patronItems["loans"][0]  	= 	NULL;		# holds loaned items, if any
	$patronItems["numLoans"]	=	0;			# number of items loaned

	$this->debug("--IN get_circ--");
	
	foreach ($XMLConvert->circulationActions->institution->action as $iteration) { 
		# Loop over the two URLs, assign the proper one to the proper variable
		$this->debug("g_c: iteration is " .  (string)$iteration["type"]  . "\n");
		
		switch($iteration["type"])	{
		
			case 'Debt':
				# Does user have a fine?  If so, store it in #patronItems["fines"]
				$this->debug("This is a debt\n");

				# Fix the URL, retrieve the debt
				$debtURL	= 	$this->fix_url((string) $iteration["href"]);
				$tempDebt	=	$this->get_webService($debtURL);
					
					# See if we got an error
					if($tempDebt	===	FALSE)	{
						error_log("Voyagerclass.php, Error retrieving fines");
						$this->mail_error("Voyagerclass.php, Error retrieving fines");
					} # end, if, debt FALSE
					
					else {
						$fineTemp	=	(string)$tempDebt->debts->institution->debt->finesum;	# Must use cast
						$fineTemp	=	(float)preg_replace("/USD/","",$fineTemp);				# Must use cast
					} # end, fines 

					$this->debug("You owe $" . $fineTemp);
					
			break; # end, case Debt

			case 'loans':
				# Loans are checked out items
				$this->debug("this is a loan\n");
				
				$loansURL	=	$this->fix_url((string)$iteration["href"]);
				$tempLoans	=	$this->get_webService($loansURL);

				$this->debug("tempLoans is " .  $tempLoans);
				$this->debug("LoansURL is " .  $loansURL);
				
				# See if we got an error
				if($tempLoans	===	FALSE) {
					error_log("Voyagerclass.php, Error retrieving loans");
					$this->mail_error("Voyagerclass.php, Error retrieving loans");
					
					return(FALSE);
					} # end, if, loans FALSE
					
					else {
						$patronItems	=	$this->parse_loans($tempLoans);
					
						# Verify patronItems didn't encounter an error
						if($patronItems	===	FALSE)	{
								error_log("Voyagerclass.php, Error retrieving patron items");
								$this->mail_error("Voyagerclass.php, Error retrieving patron items");
								return(FALSE);
						} # end, if $patronItems nothing

					} # end, patronitems good

			break; # end, case loans
			
			case 'requests':
				# REQUESTS TYPE NOT USED AT UTSA
				# ADD HANDLING CODE HERE IF WISH TO USE
				$this->debug("this is a requests\n");
					
			break; # end, case requests

			default:
				# Notifies Systems, so we can update the code, as needed, for other unexpected cases
				# May be able to predict some via ExLibris documentation
				# If field not needed, user will notice no issue
				$this->debug("We got $response in patron info.  This was unexpected\n");
				$this->mail_error("Unexpected item in patron info url XML, $response");
				$this->error_log ("Unexpected item in patron info url XML, $response");
				
		} # end, switch
		
	} # end, foreach

	if (isset($fineTemp))	{
		$patronItems["fines"]	=	$fineTemp;	# set earlier
	} # end, if, fines
	
	else {
		$patronItems["fines"]	=	0;
	} # end, else, fines
	
	$this->debug("--OUT get_circ--");

	return($patronItems);	

} # end, get_circ

private function parse_loans($Loans) {
	#  this parses the individual loaned items, placing them in a multidimensional array
	#
	# Returns:
	# 		$patronItems structure:
	# 			$patronItems["fines"]				:	int, Amount, in $US of fines owed.  0 if no current fines.(SET OUTSIDE OF THIS FUNCTION)
	#						["numLoans"]			:	int, how many items currently on loan
	#			$patronItems["loans"]				:	items loaned, array
	#			$patronItems["loans"]		{0]		:  	numerical index for each item, initialized to NULL, so if NULL, no items currently loaned
	#
	#		FALSE			:		If error occurs.
	#
	# Parameters:
	#		$Loans			:		SimpleXMLElement object, containing all loaned items

	# Internal Variables
	$iteration2		=	NULL;	# for the foreach loop iterations
	$tempURL		=	NULL;	# Holds the URL parsed out for each loan
	$tempItem		=	NULL;	# Will hold the XML object of the item
	$count			=	-1;		# Keeps track of iterations for an index to the multidimensional array. Arrays start at 0
	$patronItems	=	NULL;	# Will be value returned

	$this->debug("-_-IN parse_loans_-_");

	foreach ($Loans->loans->institution->loan as $iteration2) {

		# Initialize on each iteration, just to be safe
		$tempURL	=	NULL;	# Holds the URL parsed out for each loan
		$tempItem	=	NULL;	# Will hold the XML object of the item

		$count++;	# increase the count
		
		# Grab the XML for individual loaned items
		$this->debug("Iteration2 is " .  $iteration2);

		# fix bad hostname/ips, replace invalid characters 
		$tempURL	=	$this->fix_url((string)$iteration2["href"]);

		$this->debug("TempURL for loan is " .  $tempURL);

		$tempItem	=	$this->get_webservice($tempURL);
		$this->debug("TempItem for loan is " .  $tempItem);

		# Verify that tempItem is valid
		if($tempItem	===	FALSE)	{
			return(FALSE);
		} # end, tempItem is valid?
		
		else {
			# Start building the loans array
			# CASTs are REQUIRED
			$patronItems["loans"][$count]["canRenew"] 	=	 (string)$tempItem->resource->loan["canRenew"];
			$patronItems["loans"][$count]["itemId"] 	=	 (string)$tempItem->resource->loan->itemId;
			$patronItems["loans"][$count]["title"]	 	=	 (string)$tempItem->resource->loan->title;
			$patronItems["loans"][$count]["dueDate"]	=	 (string)$tempItem->resource->loan->dueDate;
			$patronItems["loans"][$count]["StatusText"]	=	 (string)$tempItem->resource->loan->statusText;
			$patronItems["loans"][$count]["itemtype"]	=	 (string)$tempItem->resource->loan->itemtype;
			$patronItems["loans"][$count]["callNumber"] =	 (string)$tempItem->resource->loan->callNumber;
			$patronItems["loans"][$count]["URL"] 		=	 $tempURL;	# used for renewals (renewals use the same URL, but a PUT instead of a GET

			#
			#####TRANSLATIONS OF ITEMTYPES 
			#
			
			## This may be UTSA SPECIFIC, check your own item types outside of UTSA
			# Books come up as type "circ".  This means nothing to regular users, so changing anything type circ to book
			if ($patronItems["loans"][$count]["itemtype"]	==	"circ")	{
					$patronItems["loans"][$count]["itemtype"]	=	"book";
			} # end, circ to book

			## This may be UTSA SPECIFIC, chek your own status types types outside of UTSA
			# Type of "Charged" means little to non-library personel.  Changing to Checked Out
			if ($patronItems["loans"][$count]["StatusText"]  == "Charged") {
					$patronItems["loans"][$count]["StatusText"]  = "Checked Out";
			} # end, Charged to Checked Out
			
			#
			#####END OF TRANSLATIONS

		} # else, use tempItem

	} # end, foreach $tempLoans

	$patronItems["numLoans"]	=	$count + 1;  # count is 0 indexed

	$this->debug("Loans to pass back are - :" .  $patronItems);
	$this->debug("-_-OUT parse_loans_-_");

	return($patronItems);

} # end, parse_loans

public function renew($url)	{
	# Will allow a renewal of a book
	# To Renew a book, we use the item's URL, but instead of a GET, we do a POST
		
	# Parameters:
	#	$url:	URL of item to renew
	
	# Returns 
	#	Boolean: 		True if renewed without error, False if renew failed
		
	# Internal Variables
	$mURL			=	$url;		# used for manipulating URL
	$postParameters	=	NULL;		# parameters to send in web services request.
	$webStream		=	NULL;		# Web Services stream handle	
	$filePointer	=	NULL;		# for file open on web stream
	$response		=	NULL;		# returned XML from Voyager Web Services
	$iXMLConvert	=	NULL;		# SimpleXMLobject, used for converting XML to array
	
	$this->debug("-=+IN Renew+=-");

	$mURL = preg_replace("/\|/","%7C",$url);  # URLs don't like | characters! And ExLibris sometimes puts em in!

	# Set the headers for the HTTP connection, use POST for renewal 
	$postParameters = array('http' => array(
	              			'method' => 'POST'
						)
					); # end, postParameters
	
	# Make the connection
	$webStream	=	stream_context_create($postParameters);

	$this->debug("webStream is " .  $webStream);

	$filePointer	=	fopen($mURL, 'rb', FALSE, $webStream);

	$this->debug("filePointer is " .  $filePointer);

	# Check to make sure we got something back.	
	if (!$filePointer)	{
		$this->mail_error("Problem with $mURL, $php_errormsg");
		$this->error_log("Problem with $mURL, $php_errormsg");
		return (FALSE);
	} #end, if filepointer

	$response = @stream_get_contents($filePointer);

	if ($response	===	FALSE) {
		$this->mail_error("Problem reading data from WEBSRVURL $mURL, $php_errormsg");
		$this->error_log ("Problem reading data from $mURL, $php_errormsg");
		
		return (FALSE);
	} #end, response 

	$this->debug(" response is " .  $response);

	# Exrract what we need from the XML 
	$iXMLConvert	=	new SimpleXMLElement($response);

	# Look at response code.  
	if ((string)$iXMLConvert->renewal->institution->loan->renewalStatus != "Success") {
		# if not set to success, renewal did not succeed.
		return(FALSE);
		
	} # end, iXMLConvert

	fclose($filePointer);

	$this->debug("-=+OUT Renew+=-");

	return (TRUE);  # Temporary, until real logic here.

} # end, renew

##########
# MISC UTILITY FUNCTIONS
##########

	private function mail_error($msg) {
	# used to notify systems if unexpected behaviour happens during regular use so they can be investigated
	# Most errors also sent to apache error log, but mailing the most serious/unexplained, so they can 
	# be sure to be researched
	mail ($this::ERRORMAIL, "Voyager Substitute Class Error message,voyagerclass,php\n", "This error message came from the Voyager Substitute page\n" . $msg . 
			"\n REFERER:" .  $_SERVER['HTTP_REFERER'] 	. "\n USER AGENT: " . $_SERVER['HTTP_USER_AGENT']	
	);
} #end of mail_errors
	
	private function debug($string) {
	# used fordebugging statements throughout the script, set  debug to true to have them print
	# In production, set $this->debug to false.
	#		global $this->debug;
	# flag to display lots of debugging messages
	$this->debug     	=	FALSE; 

	if ($this->debug)	{
		echo  "debug:" . $string . "<p>\n";
	} #end of if
} #end of >debug


} #end, class
?>
