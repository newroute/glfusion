<?php
// +--------------------------------------------------------------------------+
// | glFusion CMS                                                             |
// +--------------------------------------------------------------------------+
// | search.php                                                               |
// |                                                                          |
// | glFusion search class.                                                   |
// +--------------------------------------------------------------------------+
// | $Id::                                                                   $|
// +--------------------------------------------------------------------------+
// | Copyright (C) 2008 by the following authors:                             |
// |                                                                          |
// | Mark R. Evans          mark AT glfusion DOT org                          |
// |                                                                          |
// | Based on the Geeklog CMS                                                 |
// | Copyright (C) 2000-2008 by the following authors:                        |
// |                                                                          |
// | Authors: Tony Bibbs       - tony AT geeklog DOT net                      |
// |          Dirk Haun        - dirk AT haun-online DOT de                   |
// |          Sami Barakat, s.m.barakat AT gmail DOT com                      |
// +--------------------------------------------------------------------------+
// |                                                                          |
// | This program is free software; you can redistribute it and/or            |
// | modify it under the terms of the GNU General Public License              |
// | as published by the Free Software Foundation; either version 2           |
// | of the License, or (at your option) any later version.                   |
// |                                                                          |
// | This program is distributed in the hope that it will be useful,          |
// | but WITHOUT ANY WARRANTY; without even the implied warranty of           |
// | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the            |
// | GNU General Public License for more details.                             |
// |                                                                          |
// | You should have received a copy of the GNU General Public License        |
// | along with this program; if not, write to the Free Software Foundation,  |
// | Inc., 59 Temple Place - Suite 330, Boston, MA  02111-1307, USA.          |
// |                                                                          |
// +--------------------------------------------------------------------------+

if (!defined ('GVERSION')) {
    die ('This file can not be used on its own.');
}

require_once $_CONF['path_system'] . 'classes/plugin.class.php';
require_once $_CONF['path_system'] . 'classes/searchcriteria.class.php';
require_once $_CONF['path_system'] . 'classes/listfactory.class.php';

/**
* glFusion Search Class
*
* @author Tony Bibbs <tony AT geeklog DOT net>
* @package net.geeklog.search
*
*/
class Search {

    // PRIVATE VARIABLES
    var $_query = '';
    var $_topic = '';
    var $_dateStart = null;
    var $_dateEnd = null;
    var $_author = '';
    var $_type = '';
    var $_keyType = '';
    var $_names = array();
    var $_url_rewrite = array();
    var $_searchURL = '';
    var $_wordlength;

    /**
     * Constructor
     *
     * Sets up private search variables
     *
     * @author Tony Bibbs <tony AT geeklog DOT net>
     * @access public
     *
     */
    function Search()
    {
        global $_CONF, $_TABLES;

        // Set search criteria
        if (isset ($_REQUEST['query'])) {
            $this->_query = strip_tags (COM_stripslashes ($_REQUEST['query']));
        }
        if (isset ($_REQUEST['topic'])) {
            $this->_topic = COM_applyFilter ($_REQUEST['topic']);
        }
        if (isset ($_REQUEST['datestart'])) {
            $this->_dateStart = COM_applyFilter ($_REQUEST['datestart']);
        }
        if (isset ($_REQUEST['dateend'])) {
            $this->_dateEnd = COM_applyFilter ($_REQUEST['dateend']);
        }
        if (isset ($_REQUEST['author'])) {
            $this->_author = COM_applyFilter($_REQUEST['author']);

            // In case we got a username instead of uid, convert it.  This should
            // make custom themes for search page easier.
            if (!is_numeric($this->_author) && !preg_match('/^([0-9]+)$/', $this->_author) && $this->_author != '')
                $this->_author = DB_getItem($_TABLES['users'], 'uid', "username='" . addslashes ($this->_author) . "'");

            if ($this->_author < 1)
                $this->_author = '';
        }
        $this->_type = isset($_REQUEST['type']) ? COM_applyFilter($_REQUEST['type']) : 'all';
        $this->_keyType = isset($_REQUEST['keyType']) ? COM_applyFilter($_REQUEST['keyType']) : $_CONF['search_def_keytype'];
    }

    /**
     * Shows an error message to anonymous users
     *
     * This is called when anonymous users attempt to access search
     * functionality that has been locked down by the Geeklog admin.
     *
     * @author Tony Bibbs <tony AT geeklog DOT net>
     * @access private
     * @return string HTML output for error message
     *
     */
    function _getAccessDeniedMessage()
    {
        global $_CONF, $LANG_LOGIN;

        $retval .= COM_startBlock ($LANG_LOGIN[1], '',
                        COM_getBlockTemplate ('_msg_block', 'header'));
        $login = new Template($_CONF['path_layout'] . 'submit');
        $login->set_file (array ('login'=>'submitloginrequired.thtml'));
        $login->set_var ( 'xhtml', XHTML );
        $login->set_var ('login_message', $LANG_LOGIN[2]);
        $login->set_var ('site_url', $_CONF['site_url']);
        $login->set_var ('site_admin_url', $_CONF['site_admin_url']);
        $login->set_var ('layout_url', $_CONF['layout_url']);
        $login->set_var ('lang_login', $LANG_LOGIN[3]);
        if ($_CONF['disable_new_user_registration'] != 1) {
            $login->set_var ('lang_newuser', $LANG_LOGIN[4]);
        }
        $login->parse ('output', 'login');
        $retval .= $login->finish ($login->get_var('output'));
        $retval .= COM_endBlock (COM_getBlockTemplate ('_msg_block', 'footer'));

        return $retval;
    }

    /**
    * Determines if user is allowed to perform a search
    *
    * glFusion has a number of settings that may prevent
    * the access anonymous users have to the search engine.
    * This performs those checks
    *
    * @author Tony Bibbs <tony AT geeklog DOT net>
    * @access private
    * @return boolean True if search is allowed, otherwise false
    *
    */
    function _isSearchAllowed()
    {
        global $_USER, $_CONF;

        if ( !isset($_USER) || $_USER['uid'] < 2 ) {
            //check if an anonymous user is attempting to illegally access privilege search capabilities
            if (($this->_type != 'all') OR !empty($this->_dateStart) OR !empty($this->_dateEnd) OR ($this->_author > 0) OR !empty($this->_topic)) {
                if (($_CONF['loginrequired'] == 1) OR ($_CONF['searchloginrequired'] >= 1)) {
                    return false;
                }
            } else {
                if (($_CONF['loginrequired'] == 1) OR ($_CONF['searchloginrequired'] == 2)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
    * Determines if user is allowed to use the search form
    *
    * glFusion has a number of settings that may prevent
    * the access anonymous users have to the search engine.
    * This performs those checks
    *
    * @author Dirk Haun <Dirk AT haun-online DOT de>
    * @access private
    * @return boolean True if form usage is allowed, otherwise false
    *
    */
    function _isFormAllowed ()
    {
        global $_CONF, $_USER;

        if ((!isset($_USER) || $_USER['uid'] < 2) AND (($_CONF['loginrequired'] == 1) OR ($_CONF['searchloginrequired'] >= 1))) {
            return false;
        }

        return true;
    }

    /**
     * Shows search form
     *
     * Shows advanced search page
     *
     * @author Tony Bibbs <tony AT geeklog DOT net>
     * @access public
     * @return string HTML output for form
     *
     */
    function showForm ()
    {
        global $_CONF, $_TABLES, $LANG09;

        $retval = '';

        // Verify current user my use the search form
        if (!$this->_isFormAllowed()) {
            return $this->_getAccessDeniedMessage();
        }

        $retval .= COM_startBlock($LANG09[1],'advancedsearch.html');
        $searchform = new Template($_CONF['path_layout'].'search');
        $searchform->set_file (array ('searchform' => 'searchform.thtml',
                                      'authors'    => 'searchauthors.thtml'));
        $searchform->set_var( 'xhtml', XHTML );
        $searchform->set_var('search_intro', $LANG09[19]);
        $searchform->set_var('site_url', $_CONF['site_url']);
        $searchform->set_var('site_admin_url', $_CONF['site_admin_url']);
        $searchform->set_var('layout_url', $_CONF['layout_url']);
        $searchform->set_var('lang_keywords', $LANG09[2]);
        $searchform->set_var('lang_date', $LANG09[20]);
        $searchform->set_var('lang_to', $LANG09[21]);
        $searchform->set_var('date_format', $LANG09[22]);
        $searchform->set_var('lang_topic', $LANG09[3]);
        $searchform->set_var('lang_all', $LANG09[4]);
        $searchform->set_var('topic_option_list',
                            COM_topicList ('tid,topic', $this->_topic));
        $searchform->set_var('lang_type', $LANG09[5]);
        $searchform->set_var('lang_results', $LANG09[59]);
        $searchform->set_var('lang_per_page', $LANG09[60]);

        $searchform->set_var('lang_exact_phrase', $LANG09[43]);
        $searchform->set_var('lang_all_words', $LANG09[44]);
        $searchform->set_var('lang_any_word', $LANG09[45]);

        $searchform->set_var ('query', htmlspecialchars ($this->_query));
        $searchform->set_var ('datestart', $this->_dateStart);
        $searchform->set_var ('dateend', $this->_dateEnd);

        $phrase_selected = '';
        $all_selected = '';
        $any_selected = '';
        if ($this->_keyType == 'phrase') {
            $phrase_selected = 'selected="selected"';
        } else if ($this->_keyType == 'all') {
            $all_selected = 'selected="selected"';
        } else if ($this->_keyType == 'any') {
            $any_selected = 'selected="selected"';
        }
        $searchform->set_var ('key_phrase_selected', $phrase_selected);
        $searchform->set_var ('key_all_selected', $all_selected);
        $searchform->set_var ('key_any_selected', $any_selected);

        $options = '';
        $plugintypes = array('all' => $LANG09[4], 'stories' => $LANG09[6], 'comments' => $LANG09[7]);
        $plugintypes = array_merge($plugintypes, PLG_getSearchTypes());
        // Generally I don't like to hardcode HTML but this seems easiest
        foreach ($plugintypes as $key => $val) {
            $options .= "<option value=\"$key\"";
            if ($this->_type == $key)
                $options .= ' selected="selected"';
            $options .= ">$val</option>".LB;
        }
        $searchform->set_var('plugin_types', $options);

        if ($_CONF['contributedbyline'] == 1) {
            $searchform->set_var('lang_authors', $LANG09[8]);
            $searchusers = array();
            $result = DB_query("SELECT DISTINCT uid FROM {$_TABLES['comments']}");
            while ($A = DB_fetchArray($result)) {
                $searchusers[$A['uid']] = $A['uid'];
            }
            $result = DB_query("SELECT DISTINCT uid FROM {$_TABLES['stories']} WHERE (date <= NOW()) AND (draft_flag = 0)");
            while ($A = DB_fetchArray($result)) {
                $searchusers[$A['uid']] = $A['uid'];
            }

            $inlist = implode(',', $searchusers);

            if (!empty ($inlist)) {
                $sql = "SELECT uid,username,fullname FROM {$_TABLES['users']} WHERE uid IN ($inlist)";
                if (isset ($_CONF['show_fullname']) && ($_CONF['show_fullname'] == 1)) {
                    /* Caveat: This will group all users with an emtpy fullname
                     *         together, so it's not exactly sorted by their
                     *         full name ...
                     */
                    $sql .= ' ORDER BY fullname,username';
                } else {
                    $sql .= ' ORDER BY username';
                }
                $result = DB_query ($sql);
                $options = '';
                while ($A = DB_fetchArray($result)) {
                    $options .= '<option value="' . $A['uid'] . '"';
                    if ($A['uid'] == $this->_author) {
                        $options .= ' selected="selected"';
                    }
                    $options .= '>' . htmlspecialchars(COM_getDisplayName($A['uid'], $A['username'], $A['fullname'])) . '</option>';
                }
                $searchform->set_var('author_option_list', $options);
                $searchform->parse('author_form_element', 'authors', true);
            } else {
                $searchform->set_var('author_form_element', '<input type="hidden" name="author" value="0"' . XHTML . '>');
            }
        } else {
            $searchform->set_var ('author_form_element',
                    '<input type="hidden" name="author" value="0"' . XHTML . '>');
        }

        // Results per page
        $options = '';
        $limits = explode(',', $_CONF['search_limits']);
        foreach ($limits as $limit) {
            $options .= "<option value=\"$limit\"";
            if ($_CONF['num_search_results'] == $limit)
                $options .= ' selected="selected"';
            $options .= ">$limit</option>" . LB;
        }
        $searchform->set_var('search_limits', $options);

        $searchform->set_var('lang_search', $LANG09[10]);
        $searchform->parse('output', 'searchform');

        $retval .= $searchform->finish($searchform->get_var('output'));
        $retval .= COM_endBlock();

        return $retval;
    }

    /**
     * Performs search on all stories
     *
     * @author Tony Bibbs <tony AT geeklog DOT net>
     *         Sami Barakat <s.m.barakat AT gmail DOT com>
     * @access private
     * @return object plugin object
     *
     */
    function _searchStories()
    {
        global $_TABLES, $_DB_dbms, $LANG09;

        // Make sure the query is SQL safe
        $query = trim(addslashes(htmlspecialchars($this->_query)));

        $sql = "SELECT s.sid AS id, s.title AS title, s.introtext AS description, UNIX_TIMESTAMP(s.date) AS date, s.uid AS uid, s.hits AS hits, CONCAT('/article.php?story=',s.sid) AS url ";
        $sql .= "FROM {$_TABLES['stories']} AS s, {$_TABLES['users']} AS u ";
        $sql .= "WHERE (draft_flag = 0) AND (date <= NOW()) AND (u.uid = s.uid) ";
        $sql .= COM_getPermSQL('AND') . COM_getTopicSQL('AND') . COM_getLangSQL('sid', 'AND') . ' ';

        if (!empty($this->_dateStart) && !empty($this->_dateEnd))
        {
            $delim = substr($this->_dateStart, 4, 1);
            if (!empty($delim))
            {
                $DS = explode($delim, $this->_dateStart);
                $DE = explode($delim, $this->_dateEnd);
                $startdate = mktime(0,0,0,$DS[1],$DS[2],$DS[0]);
                $enddate = mktime(23,59,59,$DE[1],$DE[2],$DE[0]);
                $sql .= "AND (UNIX_TIMESTAMP(date) BETWEEN '{$this->_dateStart}' AND '{$this->_dateEnd}') ";
            }
        }
        if (!empty($this->_topic))
            $sql .= "AND (s.tid = '$this->_topic') ";
        if (!empty($this->_author))
            $sql .= "AND (s.uid = '$this->_author') ";

        $search = new SearchCriteria('stories', $LANG09[65]);
        $columns = array('introtext','bodytext','title');
        list($sql,$ftsql) = $search->buildSearchSQL($this->_keyType, $query, $columns, $sql);
        $search->setSQL($sql);
        $search->setFTSQL($ftsql);
        $search->setRank(5);
        $search->setURLRewrite(true);

        return $search;
    }

    /**
     * Performs search on all comments
     *
     * @author Tony Bibbs <tony AT geeklog DOT net>
     *         Sami Barakat <s.m.barakat AT gmail DOT com>
     * @access private
     * @return object plugin object
     *
     */
    function _searchComments()
    {
        global $_TABLES, $_DB_dbms, $LANG09;

        // Make sure the query is SQL safe
        $query = trim(addslashes(htmlspecialchars($this->_query)));

        $sql = "SELECT c.cid AS id1, s.sid AS id, c.title AS title, c.comment AS description, UNIX_TIMESTAMP(c.date) AS date, c.uid AS uid, '0' AS hits, ";

        // MSSQL has a problem when concatenating numeric values
        if ($_DB_dbms == 'mssql')
            $sql .= "'/comment.php?mode=view&amp;cid=' + CAST(c.cid AS varchar(10)) AS url ";
        else
            $sql .= "CONCAT('/article.php?story=',s.sid) AS url ";

        $sql .= "FROM {$_TABLES['users']} AS u, {$_TABLES['comments']} AS c ";
        $sql .= "LEFT JOIN {$_TABLES['stories']} AS s ON ((s.sid = c.sid) ";
        $sql .= COM_getPermSQL('AND',0,2,'s') . COM_getTopicSQL('AND',0,'s') . COM_getLangSQL('sid','AND','s') . ") ";
        $sql .= "WHERE (u.uid = c.uid) AND (s.draft_flag = 0) AND (s.commentcode >= 0) AND (s.date <= NOW()) ";

        if (!empty($this->_dateStart) && !empty($this->_dateEnd))
        {
            $delim = substr($this->_dateStart, 4, 1);
            if (!empty($delim))
            {
                $DS = explode($delim, $this->_dateStart);
                $DE = explode($delim, $this->_dateEnd);
                $startdate = mktime(0,0,0,$DS[1],$DS[2],$DS[0]);
                $enddate = mktime(23,59,59,$DE[1],$DE[2],$DE[0]);
                $sql .= "AND (UNIX_TIMESTAMP(c.date) BETWEEN '$startdate' AND '$enddate') ";
            }
        }
        if (!empty($this->_topic))
            $sql .= "AND (s.tid = '$this->_topic') ";
        if (!empty($this->_author))
            $sql .= "AND (c.uid = '$this->_author') ";

        $search = new SearchCriteria('comments', $LANG09[66]);
        $columns = array('comment','c.title');
        list($sql,$ftsql) = $search->buildSearchSQL($this->_keyType, $query, $columns, $sql);
        $search->setSQL($sql);
        $search->setFTSQL($ftsql);
        $search->setRank(2);

        return $search;
    }

    /**
     * Kicks off the appropriate search(es)
     *
     * Initiates the search engine and returns HTML formatted
     * results. It also provides support to plugins using a
     * search API. Backwards compatibility has been incorporated
     * in this function to allow legacy support to plugins using
     * the old API calls defined versions prior to Geeklog 1.5.1
     *
     * @author Sami Barakat <s.m.barakat AT gmail DOT com>
     * @access public
     * @return string HTML output for search results
     *
     */
    function doSearch()
    {
        global $_CONF, $LANG01, $LANG09, $LANG31;

        $debug_info = '';

        // Verify current user can perform requested search
        if (!$this->_isSearchAllowed())
            return $this->_getAccessDeniedMessage();

        // Make sure there is a query string
        // Full text searches have a minimum word length of 3 by default

        if ((empty($this->_query) && empty($this->_author) && empty($this->_type)) || ($_CONF['search_use_fulltext'] && strlen($this->_query) < 3))
        {
            $retval = '<p>' . $LANG09[41] . '</p>' . LB;
            $retval .= $this->showForm();

            return $retval;
        }

        // Build the URL strings
        $this->_searchURL = $_CONF['site_url'] . '/search.php?query=' . urlencode($this->_query) .
            ((!empty($this->_keyType))    ? '&amp;keyType=' . $this->_keyType : '' ) .
            ((!empty($this->_dateStart))  ? '&amp;datestart=' . $this->_dateStart : '' ) .
            ((!empty($this->_dateEnd))    ? '&amp;dateend=' . $this->_dateEnd : '' ) .
            ((!empty($this->_topic))      ? '&amp;topic=' . $this->_topic : '' ) .
            ((!empty($this->_author))     ? '&amp;author=' . $this->_author : '' );

        $url = "{$this->_searchURL}&amp;type={$this->_type}&amp;mode=";
        $obj = new ListFactory($url.'search', $_CONF['search_limits'], $_CONF['num_search_results']);
        $obj->setField('ID', 'id', false);
        $obj->setField('URL', 'url', false);

        $show_num  = $_CONF['search_show_num'];
        $show_type = $_CONF['search_show_type'];
        $show_user = $_CONF['search_show_user'];
        $show_hits = $_CONF['search_show_hits'];
        $style = isset($_CONF['search_style']) ? $_CONF['search_style'] : 'google';

        if ($style == 'table')
        {
            $obj->setStyle('table');
            //             Title        Name           Display     Sort   Format
            $obj->setField($LANG09[62], ROW_NUMBER,    $show_num,  false, '<b>%d.</b>');
            $obj->setField($LANG09[5],  SQL_TITLE,     $show_type, true,  '<b>%s</b>');
            $obj->setField($LANG09[16], 'title',       true,       true);
            $obj->setField($LANG09[63], 'description', true,       false);
            $obj->setField($LANG09[17], 'date',        true,       true);
            $obj->setField($LANG09[18], 'uid',         $show_user, true);
            $obj->setField($LANG09[50], 'hits',        $show_hits, true);
            $this->_wordlength = 7;
        }
        else if ($style == 'google')
        {
            $obj->setStyle('inline');
            $obj->setField('',          ROW_NUMBER,    $show_num,  false, '<strong>%d.</strong>');
            $obj->setField($LANG09[16], 'title',       true,       true,  '%s<br'.XHTML.'>');
            $obj->setField('',          'description', true,       false, '%s<br'.XHTML.'>');
            $obj->setField('',          '_html',       true,       false, '<span style="color:green;">');
            $obj->setField($LANG09[18], 'uid',         $show_user, true,  $LANG01[104].' %s ');
            $obj->setField($LANG09[17], 'date',        true,       true,  $LANG01[36].' %s');
            $obj->setField($LANG09[5],  SQL_TITLE,     $show_type, true,  ' - %s');
            $obj->setField($LANG09[50], 'hits',        $show_hits, true,  ' - %s '.$LANG09[50]);
            $obj->setField('',          '_html',       true,       false, '</span>');
            $this->_wordlength = 50;
        }

        if ( isset($_CONF['default_search_order']) ) {
            $obj->setDefaultSort($_CONF['default_search_order']);
        } else {
            $obj->setDefaultSort('date');
        }
        $obj->setRowFunction(Array($this, 'searchFormatCallBack'));

        // Start search timer
        $searchtimer = new timerobject();
        $searchtimer->setPercision(4);
        $searchtimer->startTimer();

        // Have plugins do their searches
        $page = isset($_REQUEST['page']) ? COM_applyFilter($_REQUEST['page'], true) : 1;
        $result_plugins = PLG_doSearch($this->_query, $this->_dateStart, $this->_dateEnd, $this->_topic, $this->_type, $this->_author, $this->_keyType, $page, 5);

        // Add core searches
        if ($this->_type == 'all' || $this->_type == 'stories')
            $result_plugins[] = $this->_searchStories();
        if ($this->_type == 'all' || $this->_type == 'comments')
            $result_plugins[] = $this->_searchComments();

        // Loop through all plugins separating the new API from the old
        $new_api = 0;
        $old_api = 0;
        $num_results = 0;

        foreach ($result_plugins as $result)
        {
            if (is_a($result, 'SearchCriteria')) {
                $debug_info .= $result->getName() . " using APIv2, ";

                $type = $result->getType();
                if ( $type == 'sql' ) {
                    if ($_CONF['search_use_fulltext'] == true && $result->getFTSQL() != '') {
                        $debug_info .= "search using FULLTEXT\n";
                        $sql = $result->getFTSQL();
                    } else {
                        $debug_info .= "search using LIKE\n";
                        $sql = $result->getSQL();
                    }

                    $sql = $this->_convertsql($sql);

                    $debug_info .= "\tSQL = " . print_r($sql,1) . "\n";

                    $obj->setQuery($result->getLabel(), $result->getName(), $sql, $result->getRank());
                    $this->_url_rewrite[ $result->getName() ] = $result->UrlRewriteEnable() ? true : false;
                } else if ($type == 'text') {
                    $obj->setQueryText($result->getLabel(), $result->getName(), $this->_query, $result->getNumResults(), $result->getRank());
                }
                $new_api++;
            }
            else if (is_a($result, 'Plugin') && $result->num_searchresults != 0)
            {
                // Some backwards compatibility
                $debug_info .= $result->plugin_name . " using APIv1, search using backwards compatibility\n";

                // Find the column heading names that closely match what we are looking for
                // There may be issues here on different languages, but this _should_ capture most of the data
                $col_title = $this->_findColumn($result->searchheading, array($LANG09[16],$LANG31[4],'Question'));//Title,Subject
                $col_desc = $this->_findColumn($result->searchheading, array($LANG09[63],'Answer'));
                $col_date = $this->_findColumn($result->searchheading, array($LANG09[17]));//'Date','Date Added','Last Updated','Date & Time'
                $col_user = $this->_findColumn($result->searchheading, array($LANG09[18],'Submited by'));
                $col_hits = $this->_findColumn($result->searchheading, array($LANG09[50],$LANG09[23],'Downloads','Clicks'));//'Hits','Views'
                $col_url  = $this->_findColumn($result->searchheading, array('URL'));//'Hits','Views'

                $label = str_replace($LANG09[59], '', $result->searchlabel);

                if ( $result->num_itemssearched > 0 ) {
                    $_page = isset($_REQUEST['page']) ? COM_applyFilter($_REQUEST['page'], true) : 1;
                    if (isset($_REQUEST['results'])) {
                        $_per_page = COM_applyFilter($_REQUEST['results'], true);
                    } else {
                        $_per_page = $obj->getPerPage();
                    }
                    $obj->addTotalRank(3);
                    $pp = round((3 / $obj->getTotalRank()) * $_per_page);
                    $offset = ($_page - 1) * $pp;
                    $limit  = $pp;

                    $obj->addToTotalFound($result->num_itemssearched);

                    $counter = 0;

                    // Extract the results
                    foreach ($result->searchresults as $old_row)
                    {
                        if ( $counter >= $offset && $counter <= ($offset+$limit) ) {
                            if ($col_date != -1)
                            {
                                // Convert the date back to a timestamp
                                $date = $old_row[$col_date];
                                $date = substr($date, 0, strpos($date, '@'));
                                if ($date == '')
                                    $date = $old_row[$col_date];
                                else
                                    $date = strtotime($date);
                            }

                            $api_results = array(
                                        SQL_NAME =>       $result->plugin_name,
                                        SQL_TITLE =>      $label,
                                        'title' =>        $col_title == -1 ? $_CONF['search_no_data'] : $old_row[$col_title],
                                        'description' =>  $col_desc == -1 ? $_CONF['search_no_data'] : $old_row[$col_desc],
                                        'date' =>         $col_date == -1 ? '&nbsp;' : $date,
                                        'uid' =>          $col_user == -1 ? '' : $old_row[$col_user],
                                        'hits' =>         $col_hits == -1 ? '0' : str_replace(',', '', $old_row[$col_hits]),
                                        'url' =>          $old_row[$col_url]
                                    );

                            $obj->addResult($api_results);
                        }
                        $counter++;
                    }
                }
//                $num_results += $counter;
                $old_api++;
            }
        }
        // Find out how many plugins are on the old/new system
        $debug_info .= "\nAPIv1: $old_api\nAPIv2: $new_api";

        // Execute the queries
        $results = $obj->ExecuteQueries();

        // Searches are done, stop timer
        $searchtime = $searchtimer->stopTimer();

        $escquery = htmlspecialchars($this->_query);
        if ($this->_keyType == 'any')
        {
            $searchQuery = str_replace(' ', "</b>' " . $LANG09[57] . " '<b>", $escquery);
            $searchQuery = "<b>'$searchQuery'</b>";
        }
        else if ($this->_keyType == 'all')
        {
            $searchQuery = str_replace(' ', "</b>' " . $LANG09[56] . " '<b>", $escquery);
            $searchQuery = "<b>'$searchQuery'</b>";
        }
        else
            $searchQuery = $LANG09[55] . " '<b>$escquery</b>'";

        $retval = "{$LANG09[25]} $searchQuery. ";
        if (count($results) == 0)
        {
            $retval .= sprintf($LANG09[24], 0);
            $retval = '<p>' . $retval . '</p>' . LB;
            $retval .= '<p>' . $LANG09[13] . '</p>' . LB;
            $retval .= $this->showForm();
        }
        else
        {
            $retval .= $LANG09[64] . " ($searchtime {$LANG09[27]}). " . COM_createLink($LANG09[61], $url.'refine');
            $retval = '<p>' . $retval . '</p>' . LB;
            $retval = $obj->getFormattedOutput($results, $LANG09[11], $retval, '', $_CONF['search_show_sort'], $_CONF['search_show_limit']);
        }

//        echo '<pre>'.$debug_info.'</pre>';
        return $retval;
    }

    /**
     * CallBack function for the ListFactory class
     *
     * This function gets called by the ListFactory class and formats
     * each row accordingly for example pulling usernames from the
     * users table and displaying a link to their profile.
     *
     * @author Sami Barakat <s.m.barakat AT gmail DOT com>
     * @access public
     * @param array $row An array of plain data to format
     * @return array A reformatted version of the input array
     *
     */
    function searchFormatCallBack($preSort, $row)
    {
        global $_CONF;

        if ($preSort)
        {
            $row[SQL_TITLE] = is_array($row[SQL_TITLE]) ? implode($_CONF['search_separator'],$row[SQL_TITLE]) : $row[SQL_TITLE];

            if (is_numeric($row['uid']))
            {
                if (empty($this->_names[ $row['uid'] ]))
                {
                    $this->_names[ $row['uid'] ] = htmlspecialchars(COM_getDisplayName( $row['uid'] ));
                    if ($row['uid'] != 1)
                        $this->_names[$row['uid']] = COM_createLink($this->_names[ $row['uid'] ],
                                    $_CONF['site_url'] . '/users.php?mode=profile&amp;uid=' . $row['uid']);
                }
                $row['uid'] = $this->_names[ $row['uid'] ];
            }
        }
        else
        {
            $row[SQL_TITLE] = COM_createLink($row[SQL_TITLE], $this->_searchURL.'&amp;type='.$row[SQL_NAME].'&amp;mode=search');

            $row['url'] = ($row['url'][0] == '/' ? $_CONF['site_url'] : '') . $row['url'];
            if ($this->_url_rewrite[$row[SQL_NAME]])
                $row['url'] = COM_buildUrl($row['url']);
//            $row['url'] .= (strpos($row['url'],'?') ? '&amp;' : '?') . 'query=' . urlencode($this->_query);

            if ( $row['title'] == '' ) {
                $row['title'] = $row[SQL_TITLE];
            }

            $row['title'] = $this->_shortenText($this->_query, $row['title'], 6);
            $row['title'] = str_replace('$', '&#36;', $row['title']);
            $row['title'] = COM_createLink($row['title'], $row['url']);

            if ( $row['description'] == '' ) {
                $row['description'] = $_CONF['search_no_data'];
            }

            if ($row['description'] != $_CONF['search_no_data'])
                $row['description'] = $this->_shortenText($this->_query, $row['description'], $this->_wordlength);

            $row['date'] = @strftime($_CONF['daytime'], $row['date']);
            $row['hits'] = COM_NumberFormat($row['hits']).' '; // simple solution to a silly problem!
        }

        return $row;
    }

    /**
     * Shortens a long text string to only a few words
     *
     * Returns a shorter version of the in putted text centred
     * around the keyword. The keyword is highlighted in bold.
     * Adds '...' to the beginning or the end of the shortened
     * version depending where the text was cut. Works on a
     * word basis, so long words wont get cut.
     *
     * @author Sami Barakat <s.m.barakat AT gmail DOT com>
     * @access private
     * @param string $keyword The word to centre around
     * @param string $text The complete text string
     * @param integer $num_words The number of words to display, best to use an odd number
     * @return string A short version of the text
     *
     */
    function _shortenText($keyword, $text, $num_words = 7)
    {
        $text = strip_tags($text);
        $words = explode(' ', $text);
        if (count($words) <= $num_words)
            return COM_highlightQuery($text, $keyword, 'b');

        $rt = '';
        if ( $keyword == '' ) {
            $pos = false;
        } else {
            $pos = stripos($text, $keyword);
        }
        if ($pos !== false)
        {
            $pos_space = strpos($text, ' ', $pos);
            if (empty($pos_space))
            {
                // Keyword at the end of text
                $key = count($words);
                $start = 0 - $num_words;
                $end = 0;
                $rt = '<b>...</b> ';
            }
            else
            {
                $str = substr($text, $pos, $pos_space - $pos);
                $key = array_search($str, $words);
                $m = ($num_words - 1) / 2;
                if ($key <= $m)
                {
                    // Keyword at the start of text
                    $start = 0;
                    $end = $num_words - 1;
                }
                else
                {
                    // Keyword in the middle of text
                    $start = 0 - $m;
                    $end = $m;
                    $rt = '<b>...</b> ';
                }
            }
        }
        else
        {
            $key = 0;
            $start = 0;
            $end = $num_words - 1;
        }

        for ($i = $start; $i <= $end; $i++)
            $rt .= $words[$key + $i] . ' ';
        $rt .= ' <b>...</b>';

        return COM_highlightQuery($rt, $keyword, 'b');
    }

    /**
     * Finds the similarities between heading names
     *
     * Returns the index of a heading that matches a
     * number of similar heading names. Used for backwards
     * compatibility in the doSearch() function.
     *
     * @author Sami Barakat <s.m.barakat AT gmail DOT com>
     * @access private
     * @param array $headings All the headings
     * @param array $find An array of alternative headings to find
     * @return integer The index of the alternative heading
     *
     */
    function _findColumn($headings, $find)
    {
        // We can't use normal for loops here as some of the
        // heading indexes start from 1, so foreach works better
        foreach ($find as $fh)
        {
            $j = 0;
            foreach ($headings as $h)
            {
                if (preg_match("/$fh/i", $h) > 0)
                    return $j;
                $j++;
            }
        }
        return -1;
    }

    /**
     * Converts the MySQL CONCAT function to the MSSQL equivalent
     *
     * @author Sami Barakat <s.m.barakat AT gmail DOT com>
     * @access private
     * @param string $sql The SQL to convert
     * @return string MSSQL friendly SQL
     *
     */
    function _convertsql($sql)
    {
        global $_DB_dbms;
        if ($_DB_dbms == 'mssql')
        {
            if (is_string($sql))
                $sql = preg_replace("/CONCAT\(([^\)]+)\)/ie", "preg_replace('/,?(\'[^\']+\'|[^,]+),/i', '\\\\1 + ', '\\1')", $sql);
            else if (is_array($sql))
                $sql['mssql'] = preg_replace("/CONCAT\(([^\)]+)\)/ie", "preg_replace('/,?(\'[^\']+\'|[^,]+),/i', '\\\\1 + ', '\\1')", $sql['mssql']);
        }
        return $sql;
    }
}

?>