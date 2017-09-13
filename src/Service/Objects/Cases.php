<?php

namespace Casebox\CoreBundle\Service\Objects;

use Casebox\CoreBundle\Service\Cache;
use Casebox\CoreBundle\Service\Security;
use Casebox\CoreBundle\Service\Tasks;
use Casebox\CoreBundle\Service\Objects;
use Casebox\CoreBundle\Service\Util;
use Casebox\CoreBundle\Service\User;
use Casebox\CoreBundle\Service\Log;
use Casebox\CoreBundle\Service\Solr\Client;
use Casebox\CoreBundle\Service\Objects\Plugins\Files;
use Casebox\CoreBundle\Service\Objects\Plugins\ContentItems;

/**
 * Class Cases
 * Custom class for cases
 */
class Cases extends Object
{
    public static $STATUS_NONE = 0;

    public static $STATUS_OVERDUE = 1;

    public static $STATUS_ACTIVE = 2;

    public static $STATUS_CLOSED = 3;

    public static $STATUS_PENDING = 4;
	
	public static $STATUS_INFORMATION = 5;

    public static $USERSTATUS_NONE = 0;

    public static $USERSTATUS_ONGOING = 1;

    public static $USERSTATUS_DONE = 2;

    /**
     * Create a task with specified params
     *
     * @param array $p object properties
     *
     * @return int
     */
    public function create($p = false)
    {
        if ($p === false) {
            $p = $this->data;
        }
		$p['pid']=150;
        $this->data = $p;

        $this->setParamsFromData($p);
		$createResult = parent::create($p);
		
		if (!empty($p['data']['_numberinhousehold']))
		{
			$householdMembers = $p['data']['_numberinhousehold'];
			if (is_numeric($householdMembers)) {
				for ($x = 0; $x < $householdMembers; $x++) {
					if ($x < 10)
					{
							$data = [
								'pid' => $createResult,
								'title' => 'Family Member',
								'template_id' => 289,
								'path' => 'Tree/Clients',
								'view' => 'edit',
								'name' => 'New Family Member',
								'data' => [],
							];
							$objService = new Objects();
							$newReferral =$objService->create($data);
					}
				}
			}
		}
		
        return $createResult;
    }

    /**
     * Load custom data for tasks
     * Note: should be removed after tasks upgraded and custom task tables removed
     */
    protected function loadCustomData()
    {
        parent::loadCustomData();

        $d = &$this->data;

        if (empty($d['data'])) {
            $d['data'] = [];
        }

        // $cd = &$d['data']; //custom data
        // $sd = &$d['sys_data']; //sys_data

        Tasks::setTaskActionFlags($d);
    }

    /**
     * Update object
     *
     * @param array|bool|false $p Optional properties. If not specified then $this-data is used
     *
     * @return boolean
     */
    public function update($p = false)
    {
        if ($p === false) {
            $p = $this->data;
        }
        $this->data = $p;

        $this->setParamsFromData($p);

        return parent::update($p);
    }

    /**
     * Analyze object data and set 'wu' property in sys_data
     *
     * return newly assigned ids
     */
    protected function setFollowers()
    {
        $rez = parent::setFollowers();

        $d = &$this->data;
        $sd = &$d['sys_data'];

        //add creator as follower for tasks
        if (!in_array($d['cid'], $sd['wu'])) {
            $sd['wu'][] = intval($d['cid']);
            $rez[] = intval($d['cid']);
        }

        $oldAssigned = [];
        if (!empty($this->oldObject)) {
            $oldAssigned = Util\toNumericArray(@$this->oldObject->getFieldValue('assigned', 0)['value']);
        }

        $newAssigned = Util\toNumericArray(@$this->getFieldValue('assigned', 0)['value']);
        $diff = array_diff($newAssigned, $oldAssigned);
        $wu = empty($sd['wu']) ? [] : $sd['wu'];

        $wu = array_merge($wu, $diff);

        $rez = array_merge($rez, $newAssigned);

        // Analyze referenced users from description
        if (!empty($d['data']['description'])) {
            $uids = Util\getReferencedUsers($d['data']['description']);
            if (!empty($uids)) {
                $wu = array_merge($wu, $uids);
                $rez = array_merge($rez, $wu);
            }
        }
        $sd['wu'] = array_unique($wu);

        $rez = array_unique($rez);

        return $rez;
    }

    /**
     * Method to collect solr data from object data
     * according to template fields configuration
     * and store it in sys_data under "solr" property
     */
    protected function collectSolrData()
    {
        parent::collectSolrData();

        $d = &$this->data;
        $sd = &$d['sys_data'];
        $solrData = &$sd['solr'];

        $template = $this->getTemplate();

        $solrData['task_status'] = @$sd['task_status'];

		$solrData['case_status'] = @$sd['case_status'];
		
		$assessments_reported = Util\toNumericArray($this->getFieldValue('assessments_reported', 0)['value']);
        if (!empty($assessments_reported)) {
            $solrData['assessments_reported'] = $assessments_reported;
        }

		$assessments_needed = Util\toNumericArray($this->getFieldValue('assessments_needed', 0)['value']);
        if (!empty($assessments_needed)) {
            $solrData['assessments_needed'] = $assessments_needed;
        }
		
        $user_ids = Util\toNumericArray($this->getFieldValue('assigned', 0)['value']);
        if (!empty($user_ids)) {
            $solrData['task_u_assignee'] = $user_ids;
            $solrData['assignee_s'] = User::getDisplayName($user_ids[0]);
        }
        else
        {
            $solrData['assignee_s'] = 'Unassigned';
        }

        $user_ids[] = @Util\coalesce($d['oid'], $d['cid']);

        $solrData['task_u_all'] = array_unique($user_ids);
		
		
		// Select only required properties for result
        $properties = [
            'race',
            'gender',
            'maritalstatus',
            'ethnicity',
            'language',
            'headofhousehold',
            'fematier',			
			'full_address',
			'task_d_closed',
			'assessments_reported',
			'assessments_needed',
			'assessments_completed',
			'assessments_started',
			'referrals_needed',
			'referrals_completed',
			'referrals_started',
			'task_u_done',
			'task_u_ongoing',
			'lat_lon',
			'county',
			'street_s',
			'city_s',
			'state_s',
			'location_type',
            'at_risk_population_ss',
            'identified_unmet_needs_ss'
        ];
        foreach ($properties as $property) {
			unset($solrData[$property]);
			if (!empty($sd[$property]))
			{
				$solrData[$property] = $sd[$property];
			}
        }
		foreach ($sd as $key => $value)
		{
			if ((substr($key, -2,1) === '_') || (substr($key, -3,1) === '_'))
			{
				$solrData[$key] = $value;
			}
		}
		
		if (!empty($sd['full_address']))
		{
			$results = $this->lookup($sd['full_address']);
			if ($results != null)
			{
				$solrData['lat_lon'] = $results['latitude'] .','.$results['longitude'];
				$solrData['full_address'] = $results['street'];//$results['full_address'];
				$solrData['county'] = $results['county'];
				$solrData['street_s'] = $results['street'];
				$solrData['city_s'] = $results['city'];
				$solrData['state_s'] = $results['state'];				
				$solrData['location_type'] = $results['location_type'];	
			}
		}
		
        if (!empty($sd['task_d_closed'])) {
            $solrData['task_ym_closed'] = str_replace('-', '', substr($sd['task_d_closed'], 2, 5));
        }

        // Set class
        $solrData['cls'] = $template->formatValueForDisplay(
            $template->getField('color'),
            $this->getFieldValue('color', 0)['value'],
            false
        );
    }
	
    /**
     * 
     * http://www.andrew-kirkpatrick.com/2011/10/google-geocoding-api-with-php/
	 *
     */	
	protected function lookup($string){
 
	   $string = str_replace (" ", "+", urlencode($string));
	   $details_url = "http://maps.googleapis.com/maps/api/geocode/json?address=".$string."&sensor=false";
	 
	   $ch = curl_init();
	   curl_setopt($ch, CURLOPT_URL, $details_url);
	   curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
	   $response = json_decode(curl_exec($ch), true);
	 
	   // If Status Code is ZERO_RESULTS, OVER_QUERY_LIMIT, REQUEST_DENIED or INVALID_REQUEST
	   if ($response['status'] != 'OK') {
		return null;
	   }
	 
	   //print_r($response);
	   $geometry = $response['results'][0]['geometry'];
	   
	   $location = array();

  foreach ($response['results'][0]['address_components'] as $component) {
    switch ($component['types']) {
      case in_array('street_number', $component['types']):
        $location['street_number'] = $component['long_name'];
      case in_array('route', $component['types']):
        $location['street'] = $component['long_name'];
      case in_array('sublocality', $component['types']):
        $location['sublocality'] = $component['long_name'];
      case in_array('locality', $component['types']):
        $location['locality'] = $component['long_name'];
      case in_array('administrative_area_level_2', $component['types']):
        $location['admin_2'] = $component['long_name'];
      case in_array('administrative_area_level_1', $component['types']):
        $location['admin_1'] = $component['long_name'];
      case in_array('postal_code', $component['types']):
        $location['postal_code'] = $component['long_name'];
      case in_array('country', $component['types']):
        $location['country'] = $component['long_name'];
    }

  }
	   
	   
	   
		$array = array(
			'longitude' => $geometry['location']['lng'],
			'latitude' => $geometry['location']['lat'],
			'location_type' => $geometry['location_type'],
			'street_number' => $location['street_number'],
			'street' => $location['street_number']. ' ' . $location['street'],
			'city' => $location['locality'],	
			'state' => $location['admin_1'],				
			'full_address' => $response['results'][0]['formatted_address'],
			'county' => $location['admin_2']
		);
	 
		return $array;
	 
	}
		
    /**
     * Set "sys_data" params from object data
     *
     * @param array &$p
     */
    protected function setParamsFromData(&$p)
    {
        $d = &$p['data'];

        if (empty($d['sys_data'])) {
            $d['sys_data'] = [];
        }

        $sd = &$p['sys_data'];
		
		unset($sd['full_address']);
		
		// Select only required properties for result
        $properties = [
            'race',
            'gender',
            'maritalstatus',
            'ethnicity',
            'language',
			'age',
			'fematier',
            'headofhousehold'
        ];
        foreach ($properties as $property) {
			unset($sd[$property]);
			if ($this->getFieldValue('_' . $property, 0)['value'] != null) {
				$obj = Objects::getCachedObject($this->getFieldValue('_' . $property, 0)['value']);
				if ($property == 'fematier')
				{
					$arr = explode(" -", $obj->getHtmlSafeName(), 2);
					$first = $arr[0];
					$sd[$property] = $first;
				}
				else
				{
					$sd[$property] = empty($obj) ? '' : str_replace('Yes - ','',$obj->getHtmlSafeName());
				}
			}
        }
        $arrayproperties = [
            'at_risk_population',
            'identified_unmet_needs'
        ];
    	foreach ($arrayproperties as $property) {
    		unset($sd[$property.'_ss']);
			$values = $this->getFieldValue($property);
			if ($values != null) {
				foreach ($values as $v) {
					$v = is_array($v) ? @$v['value'] : $v;
					$v = Util\toNumericArray($v);
					foreach ($v as $id) {
						$obj = Objects::getCachedObject($id);	
						$sd[$property.'_ss'][] = empty($obj) ? '' : str_replace('Yes - ','',$obj->getHtmlSafeName());
					}
				}
			}
        }
		$sd['full_address'] = '';
		
        if ($this->getFieldValue('_fulladdress', 0)['value'] != null) {
         $sd['full_address'] = $this->getFieldValue('_fulladdress', 0)['value'];
        }		
		
        $sd['task_due_date'] = $this->getFieldValue('due_date', 0)['value'];
        $sd['task_due_time'] = $this->getFieldValue('due_time', 0)['value'];

        $sd['task_allday'] = empty($sd['task_due_time']);

        //set date_end to be saved in tree table
        if (empty($sd['task_due_date'])) {
            $p['date_end'] = null;
        } else {
            $p['date_end'] = $sd['task_due_date'];
            if (!$sd['task_allday']) {
                $p['date_end'] = substr($sd['task_due_date'], 0, 10).' '.$sd['task_due_time'];
            }
        }

        // Set assigned users
        if (empty($sd['task_u_done'])) {
            $sd['task_u_done'] = [];
        }

		if (empty($sd['assessments_completed'])) {
            $sd['assessments_completed'] = [];
        }
		
		if (empty($sd['assessments_reported'])) {
            $sd['assessments_reported'] = [];
        }
		
		$ASSESSMENT_MAP = array(
		   1511=>510, //behavioral
		   1497=>533,//child
		   1507=>553,//clothing
		   1508=>482, //employment
		   1506=>1120,//fema
		   1515=>455,//finance
		   1504=>505, //food
		   1510=>559,//furniture
		   1512=>489,//health
		   1514=>440, //housing
		   1501=>656,//language
		   1505=>1175,//legal
		   1498=>651, //senior
		   1513=>172, //transportation
		   3151=>3114 //shelterassessment		   
		);
		
		$identified_unmet_needs = Util\toNumericArray($this->getFieldValue('identified_unmet_needs', 0)['value']);
		$at_risk_population = Util\toNumericArray($this->getFieldValue('at_risk_population', 0)['value']);
		foreach($ASSESSMENT_MAP as $identified=>$assessment){
			if (in_array($identified,$identified_unmet_needs)||in_array($identified,$at_risk_population))
			{
				$assessments_reported[] = $assessment;
			}
		 }		
				
		$sd['assessments_reported'] = $assessments_reported;
		
		//$sd['assessments_completed'] = array_intersect($assessments_reported, $sd['assessments_completed']);
		
		$sd['assessments_needed'] = array_diff($assessments_reported, $sd['assessments_completed']);
		
        $assigned = Util\toNumericArray($this->getFieldValue('assigned', 0)['value']);
	
		if (!empty($assigned)) {
			$d['oid'] = $assigned[0];
		}
		
        $sd['task_u_ongoing'] = array_diff($assigned, $sd['task_u_done']);

        // Set status
        $dateEnd = empty($p['date_end']) ? null : Util\dateISOToMysql($p['date_end']);
		
		if ($this->getFieldValue('_clientstatus', 0)['value'] != null) {
         $clientStatus = $this->getFieldValue('_clientstatus', 0)['value'];
		 if ($clientStatus == 1578)
		 {
			 unset($sd['task_d_closed']);
			 $status = static::$STATUS_ACTIVE;	
		 }
		 else if ($clientStatus == 1579)
		 {
             if (empty($sd['task_d_closed']))
			 {
				$sd['task_d_closed'] = date('Y-m-d\TH:i:s\Z'); 
			 }
			 $status = static::$STATUS_CLOSED;
		 }
		 else if ($clientStatus == 1577)
		 {
			 unset($sd['task_d_closed']);
			 $status = static::$STATUS_INFORMATION;
		 }
		}
		else
		{
			unset($sd['task_d_closed']);
			$d['_clientstatus'] = 1578;
			$status = static::$STATUS_ACTIVE;	
		}

        /*if (!empty($sd['task_d_closed'])) {
            $status = static::$STATUS_CLOSED;

        } elseif (!empty($dateEnd)) {
            if (strtotime($dateEnd) < strtotime('now')) {
                $status = static::$STATUS_OVERDUE;
            }
        }*/
		
        $sd['task_status'] = $status;
		$sd['case_status'] = $this->trans('caseStatus'.$status, '');
    }

    /**
     * Mark the task active
     * @return void
     */
    public function markActive()
    {
        $d = &$this->data;
        $sd = &$d['sys_data'];

        unset($sd['task_d_closed']);
		$sd['case_status'] = $this->trans('caseStatus'.static::$STATUS_ACTIVE, '');
		$d['data']['_clientstatus'] = 1578;
        $this->updateCustomData();
		$this->updateSysData();
    }

    /**
     * Mark the task active, reset done user list and update into db
     * @return void
     */
    public function setActive()
    {
        if (!$this->loaded) {
            $this->load();
        }

        $this->markActive();

        unset($this->data['sys_data']['task_u_done']);

        $this->update();

        $this->logAction('reopen', ['old' => &$this]);
    }

    /**
     * Mark the task as closed
     * @return void
     */
    public function markClosed()
    {
        $d = &$this->data;
        $sd = &$d['sys_data'];

        $sd['task_status'] = static::$STATUS_CLOSED;
        $sd['task_d_closed'] = date('Y-m-d\TH:i:s\Z');
		$sd['case_status'] = $this->trans('caseStatus'.static::$STATUS_CLOSED, '');
		$d['data']['_clientstatus'] = 1579;
		$this->updateCustomData();
		$this->updateSysData();
    }

    /**
     * Mark the task as closed and update into db
     * @return void
     */
    public function setClosed()
    {
        if (!$this->loaded) {
            $this->load();
        }

        $this->markClosed();

        $this->logAction('close', ['old' => &$this]);
    }

    /**
     * Simple function to check if task is closed
     * @return boolean
     */
    public function isClosed()
    {
        return ($this->getStatus() == static::$STATUS_CLOSED);
    }

    /**
     * Get task status
     * @return int
     */
    public function getStatus()
    {
        $d = &$this->data;
        $sd = &$d['sys_data'];

        $rez = empty($sd['task_status']) ? static::$STATUS_NONE : $sd['task_status'];

        return $rez;
    }

    /**
     * Get task status text
     *
     * @param integer|bool|false $status
     *
     * @return string
     */
    public function getStatusText($status = false)
    {
        if ($status === false) {
            $status = $this->getStatus();
        }

        return $this->trans('caseStatus'.$status, '');
    }

    /**
     * Get the css class corresponding for status color
     *
     * @param int $status
     *
     * @return string | null
     */
    public function getStatusCSSClass($status = false)
    {
        if ($status === false) {
            $status = $this->getStatus();
        }

        $rez = 'task-status';

        switch ($this->getStatus()) {
            case static::$STATUS_OVERDUE:
                $rez .= ' task-status-overdue';
                break;

            case static::$STATUS_ACTIVE:
                $rez .= ' case-status-active';
                break;

            case static::$STATUS_CLOSED:
                $rez .= ' case-status-closed';
                break;
				
            case static::$STATUS_INFORMATION:
                $rez .= ' case-status-information';
                break;				
        }

        return $rez;
    }

    /**
     * Get user status for loaded task
     *
     * @param  int|bool|false $userId
     *
     * @return integer
     */
    public function getUserStatus($userId = false)
    {
        if ($userId == false) {
            $userId = User::getId();
        }

        $d = &$this->data;
        $sd = &$d['sys_data'];

        if (in_array($userId, $sd['task_u_ongoing'])) {
            return static::$USERSTATUS_ONGOING;
        }

        if (in_array($userId, $sd['task_u_done'])) {
            return static::$USERSTATUS_DONE;
        }

        return static::$USERSTATUS_NONE;
    }

    /**
     * Change user status for loaded task
     *
     * @param integer $status
     * @param integer|bool|false $userId
     *
     * @return boolean
     */
    public function setUserStatus($status, $userId = false)
    {
		$currentUserId = User::getId();

        if ($userId == false) {
            $userId = $currentUserId;
        }
        if (!$this->loaded) {
            $this->load();
        }

        $d = &$this->data;
        $sd = &$d['sys_data'];

        $d['data']['assigned'] = $userId;
		$this->setParamsFromData($d);
        //$sd['solr']['task_u_assignee'] = $userId;
		$this->updateCustomData();
		$this->updateSysData();
		$solr = new Client();
		$solr->updateTree(['id' => $this->id]);

        $this->logAction('completion_on_behalf', ['old' => &$this]);
    }

    /**
     * Check if a task status should be changed after user status change
     */
    public function checkAutoclose()
    {
        $d = &$this->data;
        $sd = &$d['sys_data'];

        if (empty($sd['task_u_ongoing'])) {
            $this->markClosed();
        } else {
            $this->markActive();
        }
    }

    /**
     * Get action flags that a user can do to task
     *
     * @param integer|bool|false $userId
     *
     * @return array
     */
    public function getActionFlags($userId = false)
    {
        if ($userId === false) {
            $userId = User::getId();
        }

        $isAdmin = Security::isAdmin($userId);
        $isOwner = $this->isOwner($userId);
        $isClosed = $this->isClosed();
        $canEdit = !$isClosed;
		$data = $this->getData();
        $sd = &$data['sys_data'];
		//	Cache::get('symfony.container')->get('logger')->error(
	//		'sup',
//			$sd
//		);	

        $rez = [
            // 'edit' => $canEdit
			'assessments'=>array_values($sd['solr']['assessments_needed']),
			'referrals'=>array_values(!empty($sd['solr']['referrals_started'])?$sd['solr']['referrals_started']:null),
            'close' => $canEdit,
            'reopen' => ($isClosed && $isOwner)//,
            //'complete' => (!$isClosed && ($this->getUserStatus($userId) == static::$USERSTATUS_ONGOING)),
        ];

        return $rez;
    }

    /**
     * Method to get end date for the task
     * @return string | null
     */
    public function getEndDate()
    {
        $rez = null;

        $d = &$this->data;
        $sd = &$d['sys_data'];

        if (!empty($sd['task_due_date'])) {
            $rez = $sd['task_due_date'];
            if (!empty($sd['task_due_time'])) {
                $rez = substr($rez, 0, 11).$sd['task_due_time'].'Z';
                $rez = Util\userTimeToUTCTimezone($rez);
            }
        }

        return $rez;
    }

    /**
     * Generate html preview for a case
     *
     * @param int $id task id
     *
     * @return array
     */
    public function getPreviewBlocks()
    {
        $pb = parent::getPreviewBlocks();

        $data = $this->getData();
        $sd = &$data['sys_data'];

        $template = $this->getTemplate();

		//$actionsLine = '';
		$addressLine = '';
        $demographicsLine = '';
		$femaLine = '';
		$dateLines = '';
        $ownerRow = '';
        $assigneeRow = '';
        $closureReason = '';
		$identifiedNeedsLine = '';
		$atRiskLine = '';
        $coreUri = $this->configService->get('core_uri');

		/*if (!empty($sd['solr']['assessments_completed']))
		{
			$actionsLine = count($sd['solr']['assessments_completed']) . " Assessments Completed -";	
		}
		
		if (!empty($sd['solr']['assessments_needed'])) {
			$actionsLine = count($sd['solr']['assessments_needed']) . " Assessments Needed - ";
		}*/
		$demographicsLine = '#'.$data['id']. " - ";
		if (!empty($sd['solr']['gender'])) {
			$demographicsLine = $demographicsLine . $sd['solr']['gender'] . " - ";
		}
		else
		{
			$demographicsLine = $demographicsLine . "Gender not collected" . " - ";
		}		
		if (!empty($sd['solr']['race'])) {
			$demographicsLine = $demographicsLine . $sd['solr']['race'] . " - ";
		}

		if (!empty($sd['solr']['ethnicity'])) {
			$demographicsLine = $demographicsLine . $sd['solr']['ethnicity'] . " - ";	
		}
		
		if (!empty($sd['solr']['closurereason_s'])) {
			$closureReason = '<b>Record Closed</br>' . $sd['solr']['closurereason_s'] .'</b><br/>';
		}		
		
		if (!empty($sd['solr']['headofhousehold'])) {
			if ($sd['solr']['headofhousehold'] == "No")
			{
				$addressLine = $addressLine . 'Not Head of Household - ';	
			}
			else if ($sd['solr']['headofhousehold'] == "Yes")
			{
				$addressLine = $addressLine . 'Head of Household - ';	
			}
			else
			{
				$addressLine = $addressLine . 'Unknown Head of Household - ';	
			}
		}		

		if (!empty($sd['solr']['femanumber_s'])) {
			$femaLine = $femaLine . 'FEMA #' . $sd['solr']['femanumber_s'] . " - ";
		}	
		else
		{
			$femaLine = $femaLine . "FEMA # Not Collected - ";
		}

		if (!empty($sd['solr']['fematier'])) {
			$femaLine = $femaLine . $sd['solr']['fematier'] . " - ";
		}				
		
		if (!empty($sd['solr']['birthdate_dt'])) {
			$demographicsLine = $demographicsLine . Util\formatMysqlDate($sd['solr']['birthdate_dt'], Util\getOption('short_date_format')) . " - ";
		}
		
		if (!empty($sd['solr']['language'])) {
			$demographicsLine = $demographicsLine . $sd['solr']['language'] . " - ";
		}

		if (!empty($sd['solr']['emailaddress_s'])) {
			$emailLine = $sd['solr']['emailaddress_s'] . " - ";
		}
		else
		{
			$emailLine = "Email not collected" . " - ";
		}		
		
		if (!empty($sd['solr']['phonenumber_s'])) {
			$emailLine = $emailLine . $sd['solr']['phonenumber_s'] . " - ";
		}
		
		if (!empty($sd['solr']['maritalstatus'])) {
			$emailLine =  $emailLine .$sd['solr']['maritalstatus'] . " - ";
		}		
		
		if (!empty($sd['solr']['full_address'])) {
			$addressLine = $addressLine . $sd['solr']['full_address']. " - ";
		}
		else
		{
			$addressLine = $addressLine. "No address listed" . " - ";
		}
		if (!empty($sd['solr']['county'])) {
			$addressLine = $addressLine . $sd['solr']['county']. " - ";
		}

			$filePlugin = new Files();
			$files = $filePlugin->getData($data['id']);
			
			$fileInfo = '<a class="bt item-action click" action="upload" uid="'.User::getId().'">Upload Consent Form</a>';
			
			foreach ($files['data'] as $file) {
				$fileInfo = '<table style="border: 0px; border-collapse: collapse; margin: 0px; padding: 0px; " width="100%"><tr><tr><td class="obj" width="5%"><img alt="icon" class="i16u icon-assessment-familymember file-pdf icon-padding" src="/css/i/s.gif"></td><td width="90%"><a class="bt item-action click" action="file" fid="'.$file['id'].'">'.$file['name'].'</a></td></tr></table>';
			}
			$contentItems = new ContentItems();
			$items = $contentItems->getData($data['id']);
			
			$addressInfo = '';
			$familyMemberInfo = '';
			$contentRow = '';
			$familyMemberCount = 0;
			$addressCount = 0;
			if (!isset($data['udate']))
			{
				$data['udate'] = $data['cdate'];
			}
			foreach ($items['data'] as $item) {
				if ($item['template_id'] == 289)
				{
					 $familyMemberInfo = $familyMemberInfo.'<tr><td class="obj" width="5%"><img alt="icon" class="i16u icon-assessment-familymember icon-padding" src="/css/i/s.gif"></td><td width="90%"><a class="bt item-action click" myPid="'.$data['id'].'" action="editContent" templateId="289" myId="'.$item['id'].'">'.$item['name'].'</a></td><td width="5%" class="elips"> <a class="bt item-action click" myName="Family Member" action="removeContent" myPid="'.$data['id'].'" templateId="311" myId="'.$item['id'].'"><span title="Remove Address" class="click icon-cross" myName="Famly Member" action="removeContent" myPid="'.$data['id'].'" templateId="289" myId="'.$item['id'].'"></span></a></td></tr>';
					 $familyMemberCount++;
				}
				if ($item['template_id'] == 311)
				{
					 $addressInfo = $addressInfo.'<tr><td class="obj" width="5%"><img alt="icon" class="i16u icon-assessment-address icon-padding" src="/css/i/s.gif">    </td>    <td width="90%"><a class="bt item-action click" action="editContent" myPid="'.$data['id'].'" templateId="311" myId="'.$item['id'].'">'.$item['name'].'</a></td><td width="5%" class="elips"><a class="bt item-action click" myName="Address" action="removeContent" myPid="'.$data['id'].'" templateId="311" myId="'.$item['id'].'"><span title="Remove Address" class="click icon-cross" myName="Address" action="removeContent" myPid="'.$data['id'].'" templateId="311" myId="'.$item['id'].'"></span></a></td><td width="5%" class="obj" style="background-color:white !important;"></td></tr>';
					 $addressCount++;
				}
				if (isset($data['udate']) && isset($item['cdate']))
				{
				if (Util\getDatesDiff($data['udate'],$item['cdate']) > 0)
				{
					$data['udate'] = $item['cdate'];
				}
				}
				if (isset($data['udate']) && isset($item['udate']))
				{				
				if (Util\getDatesDiff($data['udate'],$item['udate']) > 0)
				{
					$data['udate'] = $item['udate'];
				}
				}
			}
			
			if ($familyMemberCount ==0)
			{
				$familyMemberInfo = $familyMemberInfo.'<table style="border: 0px; border-collapse: collapse; margin: 0px; padding: 0px; "><tr> <td></td> <td width="100%"><a class="bt item-action click" action="addContent" templateId="289" myPid="'.$data['id'].'">Add Family Member</a></td></tr>';
			}
			else
			{
				$familyMemberInfo = '<table style="border: 0px; border-collapse: collapse; margin: 0px; padding: 0px;width:100% "><tr><td width="95%"><table style="border: 0px; border-collapse: collapse; margin: 0px; padding: 0px; " class="test" width="100%">'.$familyMemberInfo. '</table></td><td width="5%" class="obj" style="vertical-align:top;"><a class="bt item-action click" title="Add Family Member" action="addContent" templateId="289" myPid="'.$data['id'].'">   <img alt="Add Family Member" title="Add Family Member" class="i16u icon-plus"  action="addContent" templateId="289" myPid="'.$data['id'].'" src="/css/i/s.gif"> </a></td></tr>'; 
			}
			if ($addressCount ==0)
			{
				$addressInfo = $addressInfo.'<table style="border: 0px; border-collapse: collapse; margin: 0px; padding: 0px; "><tr><td></td> <td width="100%"><a class="bt item-action click" action="addContent" templateId="311" myPid="'.$data['id'].'">Add Address</a></td></tr>';
			}	
			else
			{
				$addressInfo = '<table style="border: 0px; border-collapse: collapse; margin: 0px; padding: 0px;width:100% "><tr><td width="95%"><table style="border: 0px; border-collapse: collapse; margin: 0px; padding: 0px; " class="test" width="100%">'.$addressInfo. '</table></td><td width="5%" class="obj" style="vertical-align:top;"><a class="bt item-action click" title="Add Address" action="addContent" templateId="311" myPid="'.$data['id'].'">   <img alt="Add Address" title="Add Address" class="i16u icon-plus"  action="addContent" templateId="311" myPid="'.$data['id'].'" src="/css/i/s.gif"> </a></td></tr>'; 				
			}
			
			$familyMemberInfo = $familyMemberInfo . '</table>';
			$addressInfo = $addressInfo . '</table>';
        $userService = Cache::get('symfony.container')->get('casebox_core.service.user');

        //create date and status row
        $ed = $this->getEndDate();

        if (!empty($ed)) {
            $endDate = Util\formatTaskTime($ed, !$sd['task_allday']);

            $dateLines = '<tr><td class="prop-key">'.$this->trans('Due').':</td><td>'.$endDate.'</td></tr>';
            // $dateLine .= '<div class="date">' . $endDate . '</div>';
        }

        if (!empty($sd['task_d_closed'])) {
            $dateLines .= '<tr><td class="prop-key">'.
                $this->trans('Completed').':</td><td>'.
                Util\formatAgoTime($sd['task_d_closed']).'</td></tr>';
        }

        // Create owner row
        $v = $this->getOwner();
        if (!empty($v)) {
            $cn = User::getDisplayName($v);
            $cdt = Util\formatAgoTime($data['cdate']);
            $cd = Util\formatDateTimePeriod(
                $data['cdate'],
                null,
                @Cache::get('session')->get('user')['cfg']['timezone']
            );

            $ownerRow = '<tr><td class="prop-key" width="15%" style="width:15%">Intake Representative:</td><td width="35%">'.
                '<table  style="border: 0px; border-collapse: collapse; margin: 0px; padding: 0px; " width="100%" class="prop-val people"><tbody>'.
                '<tr><td class="user"><img alt="User Photo" class="photo32" src="'.
                $coreUri.'photo/'.$v.'.jpg?32='.$userService->getPhotoParam($v).
                '" style="width:32px; height: 32px" alt="'.$cn.'" title="'.$cn.'"></td>'.
                '<td>'.$cn.'<p class="gr">Intake: '.
                '<span class="dttm" title="'.$cd.'">'.$cdt.'</span></p></td></tr>';
        }

        // Create assignee row
        $v = $this->getFieldValue('assigned', 0);
		if (empty($v['value'])) {
			$assigneeRow .= '<td class="prop-key" width="15%" style="width:15%">'.$this->trans(
                    'Case Manager'
                ).':</td><td width="35%"><table  style="border: 0px; border-collapse: collapse; margin: 0px; padding: 0px; " width="100%" class="prop-val"><tbody><tr><td>'.
				'<a class="bt item-action click" action="assign" uid="'.User::getId().
				'">Assign client to me</a></td>';
		}
        else // (!empty($v['value'])) {
			{
            $assigneeRow .= '<td class="prop-key" width="15%" style="width:15%">'.$this->trans(
                    'Case Manager'
                ).':</td><td width="35%"><table class="prop-val people"><tbody>';
            $v = Util\toNumericArray($v['value']);

            $dateFormat = Util\getOption('long_date_format').' H:i:s';

            foreach ($v as $id) {
                $un = User::getDisplayName($id);
                $completed = !empty($sd['task_d_closed']);

                $cdt = ''; //completed date title
                $dateText = '';

                if ($completed) {
                    $cdt = Util\formatMysqlDate($sd['task_d_closed'], $dateFormat);
                    $dateText = ': '.Util\formatAgoTime($sd['task_d_closed']);
                }
				else
				{
					$dateText = '' .Util\formatAgoTime($data['udate']).$contentRow;
				}

                $assigneeRow .= '<td class="user"><div style="position: relative">'.
                    '<img alt="User Photo" class="photo32" src="'.$coreUri.'photo/'.$id.'.jpg?32='.$userService->getPhotoParam($id).
                    '" style="width:32px; height: 32px" alt="'.$un.'" title="'.$un.'">'.
                    ($completed ? '<img class="done icon icon-tick-circle" src="/css/i/s.gif" />' : "").
                    '</div></td><td>'.$un.''.
                    '<p class="gr" title="'.$cdt.'">'.(
                    $completed
                        ? $this->trans('Closed').$dateText
                        : 'Last Action: '.$dateText
                    ).'</td></tr>';
            }
        }			
			
			
			

        $pb[0] = '<table class="obj-preview'.$rtl.'"><tbody>'.
            $dateLines.
            $p.
            $ownerRow. '</tbody></table></td></tr><tr>'.
            $assigneeRow. '</tbody></table></td></tr>'.
            '<tbody></table>';
        $pb[1] = 
            '<div width="100%">'.
			'<table style="border: 0px; border-collapse: collapse; margin: 0px; padding: 0px; " width="100%"><tr style="vertical-align:top"><td width="70%">'.	
			$demographicsLine.trim($femaLine, " - ").'<br/>'.
			trim($emailLine.$addressLine, " - ").'<br/></td>'.
			'<td width="30%" style="text-align:right;" align="right">'.$closureReason.'<a target="_new" href="get/?pdf='.$data['id'].'">Print Recovery Plan</a></td></tr></table>';
        
		// Create description row
        $v = $this->getFieldValue('identified_unmet_needs', 0);
        if (!empty($v['value'])) {
            $tf = $template->getField('identified_unmet_needs');
            $identifiedNeedsLine = $template->formatValueForDisplay($tf, $v);
        }

	$recoveryActions = [];
        $flags = $this->getActionFlags();

        foreach ($flags as $k => $v) {
            if (!empty($v) && ($k == 'close' || $k == 'reopen')) {
                $recoveryActions[] = "<a action=\"$k\" class=\"item-action ib-$k\">".$this->trans(ucfirst($k)).'</a>';
            }
        }

        $recoveryActions = empty($recoveryActions) ? '' : '<div class="task-actions">'.implode(' ', $recoveryActions).'</div>';


		// Create description row
        $v = $this->getFieldValue('at_risk_population', 0);
        if (!empty($v['value'])) {
            $tf = $template->getField('at_risk_population');
            $atRiskLine = $template->formatValueForDisplay($tf, $v);
        }
		
		$pb[2] = 
            '<table class="obj-preview'.$rtl.'"><tbody>'.
			//'<tr class="prop-header"><th colspan="2" width="50%" style>Assigned Case Manager</th><th colspan="2" width="50%" style>Self Reported/Identified Population and Needs</th></tr>'.
            $ownerRow.'</tbody></table></td>'.
            $assigneeRow. '</tbody></table>'.
			'<tr><td class="prop-key" style="width:15%" width="15%">Client Intake:</td><td width="35%" style="width:15%" class="prop-val">'.
			'<table style="border: 0px; border-collapse: collapse; margin: 0px; padding: 0px; " width="100%"><tr>    <td class="obj" width="5%">        <img alt="icon" class="i16u icon-assessment-client icon-padding" src="/css/i/s.gif">    </td>    <td width="95%"><a class="bt item-action click" action="edit">'.$data['name'].'</a></td></tr></table></td>'.
			//'<ul class="clean"><li class="icon-padding icon-assessment-client" style="background-repeat:no-repeat !important"><a class="bt item-action click" action="edit" uid="'.User::getId().'">'.$data['name'].'</a></li></ul>
			'<td class="prop-key" style="width:15%" width="15%">Consent Form:</td><td class="prop-val" width="35%">'.$fileInfo.'</td></tr>'.
			'<tr><td class="prop-key" style="width:15%" width="15%">Family Members:</td><td width="35%" style="width:15%" class="prop-val">'.$familyMemberInfo.'</td>'.
			'<td class="prop-key" style="width:15%" width="15%">Alternative Address:</td><td class="prop-val" width="35%">'.$addressInfo.'</td></tr>'.
			'<tr><td class="prop-key" style="width:15%" width="15%">Special/At Risk Population:</td><td width="35%" style="width:15%" class="prop-val">'.$atRiskLine.'</td>'.
			'<td class="prop-key" style="width:15%" width="15%">Identified Needs:</td><td class="prop-val" width="35%">'.$identifiedNeedsLine.'</td></tr>'.
            '<tbody></table>';		
        $pb[3] = ''; 
            //'<table class="obj-preview'.$rtl.'"><tbody>'.
	//		'<tr class="prop-header"><th colspan="3" style>'.count(array_intersect($sd['solr']['assessments_completed'], $sd['solr']['assessments_reported'])).' out of '.count($sd['solr']['assessments_reported']).' assessments completed. '.count($sd['solr']['assessments_needed']).' remain to be completed</td></tr>'.
         //   '<tbody></table>';	
        $pb[4] = '';
          //  '<table class="obj-preview'.$rtl.'"><tbody>'.
	//		'<tr class="prop-header"><th colspan="3" style>'.count($sd['solr']['referrals_needed']).' assessments required referrals. '.count($sd['solr']['referrals_started']).' services need to be completed</td></tr>'.
        //    '<tbody></table>';			
        $pb[5] = ''; 
          //  '<table class="obj-preview'.$rtl.'"><tbody>'.
	//		'<tr class="prop-header"><th colspan="3" style>Recovery</td></tr>'.
         //   '<tbody></table>';			
		return $pb;
    }



}
