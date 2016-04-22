<?php

namespace Casebox\CoreBundle\Service\Objects\Plugins;

use Casebox\CoreBundle\Service\User;
use Casebox\CoreBundle\Service\Util;
use Casebox\CoreBundle\Service\Search;
use Casebox\CoreBundle\Service\Objects;

/**
 * Class SystemProperties
 */
class SystemProperties extends Base
{
    public function getData($id = false)
    {
        $rez = [
            'success' => true,
            'data' => [],
        ];

        parent::getData($id);

        $obj = $this->getObjectClass();

        if (!is_object($obj)) {
            return $rez;
        }

        $data = $obj->getData();

        $rez['data'] = array_intersect_key(
            $data,
            [
                'id' => 1,
                'name' => 1,
                'template_id' => 1,
                'cid' => 1,
                'cdate' => 1,
                'uid' => 1,
                'udate' => 1,
                'dstatus' => 1,
                'did' => 1,
                'ddate' => 1,
                'size' => 1,
            ]
        );
        $d = &$rez['data'];

        $pids = Util\toNumericArray($data['pids']);
        array_pop($pids);
        $d['pids'] = $d['path'] = implode('/', $pids);

        $arr = [&$d];
        Search::setPaths($arr);

        $d['template_name'] = Objects::getName($d['template_id']);

        $sd = $obj->getSysData();
        $userId = User::getId();

        $d['subscription'] = 'ignore';
        if (!empty($sd['fu']) && in_array($userId, $sd['fu'])) {
            $d['subscription'] = 'watch'; //follow
        }
        if (!empty($sd['wu']) && in_array($userId, $sd['wu'])) {
            $d['subscription'] = 'watch';
        }

        $d['cid_text'] = User::getDisplayName($d['cid']);

        $d['cdate_ago_text'] = Util\formatAgoTime($d['cdate']);
        $d['cdate'] = Util\dateMysqlToISO($d['cdate']);
        $d['udate'] = Util\dateMysqlToISO($d['udate']);

        $d['uid_text'] = User::getDisplayName($d['uid']);
        $d['udate_ago_text'] = Util\formatAgoTime($d['udate']);

        if (!empty($d['dstatus'])) {
            $d['did_text'] = User::getDisplayName($d['did']);
            $d['ddate_text'] = Util\formatAgoTime($d['ddate']);
        }

        return $rez;
    }
}
