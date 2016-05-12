<?php

namespace Casebox\CoreBundle\Service\TreeNode;

use Casebox\CoreBundle\Service\DataModel as DM;
use Casebox\CoreBundle\Service\Util;
use Casebox\CoreBundle\Service\Security;
use Casebox\CoreBundle\Service\User;
use Casebox\CoreBundle\Service\Log;

class ActionLog extends Base
{
    /**
     * check if current class is configured to return any result for
     * given path and request params
     *
     * @param  array &$pathArray
     * @param  array &$requestParams
     *
     * @return boolean
     */
    protected function acceptedPath(&$pathArray, &$requestParams)
    {
        return (
            parent::acceptedPath($pathArray, $requestParams) &&
            Security::isAdmin()
        );
    }

    protected function createDefaultFilter()
    {
        $this->fq = [
            'core_id:'.$this->configService->get('core_id'),
        ];
    }

    public function getChildren(&$pathArray, $requestParams)
    {

        $this->path = $pathArray;
        $this->lastNode = @$pathArray[sizeof($pathArray) - 1];
        $this->requestParams = &$requestParams;

        if (!$this->acceptedPath($pathArray, $requestParams)) {
            return;
        }

        $ourPid = @intval($this->config['pid']);

        $this->createDefaultFilter();

        if (empty($this->lastNode) ||
            (($this->lastNode->id == $ourPid) && (get_class($this->lastNode) != get_class($this)))
        ) {
            $rez = $this->getRootNodes();
        } else {
            switch (substr($this->lastNode->id, 0, 1)) {
                case 'a':
                    $rez = $this->getLogGroups();
                    break;
                case 'g':
                    $rez = $this->getUsers();
                    break;

                case 'q':
                    $rez = $this->getTypes();
                    break;

                default:
                    $rez = $this->getLogRecords();
                    break;
            }
        }

        return $rez;

    }

    public function getName($id = false)
    {
        if ($id === false) {
            $id = $this->id;
        }

        $rez = $id;

        switch (substr($id, 0, 1)) {
            case 'a':
                $rez = $this->trans('ActionLog');
                break;

            case 'd':
                $rez = Util\formatAgoDate(substr($id, 1, 10));
                break;

            case 'm':
                $rez = $this->trans('CurrentMonth');
                break;

            case 'g':
                $rez = $this->trans('Users');
                break;

            case 'q':
                $rez = $this->trans('Type');
                break;

            case 'u':
                $rez = User::getDisplayName(substr($id, 1));
                break;

            case 't':
                $rez = Util\coalesce($this->trans('at'.substr($id, 1)), substr($id, 1));
                break;
            
            default:
                if (!empty($id) && is_numeric($id)) {
                    $r = DM\Log::read($id);

                    if (!empty($r)) {
                        $rez = Util\coalesce($r['data']['name'], 'unknown');
                    }
                }
                break;
        }

        return $rez;
    }

    protected function getRootNodes()
    {
        return [
            'data' => [
                [
                    'name' => $this->getName('actionLog'),
                    'id' => $this->getId('actionLog'),
                    'iconCls' => 'i-book-open',
                    'has_childs' => true,
                ],
            ],
        ];
    }

    public function getLogGroups()
    {
        $rez = ['data' => []];
        $s = Log::getSolrLogConnection();

        if (empty($s)) {
            return $rez;
        }

        $p = [
            'rows' => 0,
            'facet' => 'true',
            'facet.mincount' => 1,
            'facet.sort' => 'index',
            'facet.range' => 'action_date',
            'facet.range.start' => 'NOW/DAY-7DAY',
            'facet.range.end' => 'NOW/DAY+1DAY',
            'facet.range.gap' => '+1DAY',
            'fq' => $this->fq,
            'facet.query' => [
                '{!ex=action_date key=action_date}action_date:["'.date('Y-m').'-01T00:00:00Z" TO *]',
            ],
        ];

        $sr = $s->query($p);

        if (!empty($sr->facet_counts->facet_ranges->action_date->counts)) {
            foreach ($sr->facet_counts->facet_ranges->action_date->counts as $k => $v) {
                $k = 'd'.substr($k, 0, 10);
                $rez['data'][$k] = [
                    'name' => $this->getName($k).' ('.$v.')',
                    'id' => $this->getId($k),
                    'iconCls' => 'icon-folder',
                    'has_childs' => false,
                ];
            }
        }
        krsort($rez['data']);
        $rez['data'] = array_values($rez['data']);

        if (!empty($sr->facet_counts->facet_queries->action_date)) {
            $k = 'month';
            $rez['data'][] = [
                'name' => $this->getName($k).' ('.$sr->facet_counts->facet_queries->action_date.')',
                'id' => $this->getId($k),
                'iconCls' => 'icon-folder',
                'has_childs' => false,
            ];
        }

        $rez['data'][] = [
            'name' => $this->getName('g'),
            'id' => $this->getId('g'),
            'iconCls' => 'icon-folder',
            'has_childs' => true,
        ];

        $rez['data'][] = [
            'name' => $this->getName('q'),
            'id' => $this->getId('q'),
            'iconCls' => 'icon-folder',
            'has_childs' => true,
        ];

        return $rez;
    }

    public function getUsers()
    {
        $rez = ['data' => []];
        $s = Log::getSolrLogConnection();

        $p = [
            'rows' => 0,
            'facet' => 'true',
            'facet.mincount' => 1,
            'fq' => $this->fq,
            'facet.field' => [
                '{!ex=user_id key=user_id}user_id',
            ],
        ];

        $sr = $s->search('*:*', 0, 0, $p);

        if (!empty($sr->facet_counts->facet_fields->user_id)) {
            foreach ($sr->facet_counts->facet_fields->user_id as $k => $v) {
                $k = 'u'.$k;
                $rez['data'][] = [
                    'name' => $this->getName($k).' ('.$v.')',
                    'id' => $this->getId($k),
                    'iconCls' => 'icon-user',
                    'has_childs' => false,
                ];
            }
        }

        return $rez;
    }

    public function getTypes()
    {
        $rez = ['data' => []];
        $s = Log::getSolrLogConnection();

        $p = [
            'rows' => 0,
            'facet' => 'true',
            'facet.mincount' => 1,
            'fq' => $this->fq,
            'facet.field' => [
                '{!ex=action_type key=action_type}action_type',
            ],
        ];

        $sr = $s->search('*:*', 0, 0, $p);

        if (!empty($sr->facet_counts->facet_fields->action_type)) {
            foreach ($sr->facet_counts->facet_fields->action_type as $k => $v) {
                $k = 't'.$k;
                $rez['data'][] = [
                    'name' => $this->getName($k).' ('.$v.')',
                    'id' => $this->getId($k),
                    'iconCls' => 'icon-folder',
                    'has_childs' => false,
                ];
            }
        }

        return $rez;
    }

    public function getLogRecords()
    {
        $s = Log::getSolrLogConnection();

        $this->requestParams['sort'] = ['action_date desc'];

        $p = [
            'rows' => 50,
            'fl' => 'id,action_id,user_id,object_id,object_pid,object_data',
            'fq' => $this->fq,
            'strictSort' => 'action_date desc',
        ];

        $id = substr($this->lastNode->id, 1);

        switch (substr($this->lastNode->id, 0, 1)) {
            case 'd':
                $p['fq'][] = 'action_date:["'.$id.'T00:00:00Z" TO "'.$id.'T23:59:99Z"]';
                break;

            case 'm':
                $p['fq'][] = 'action_date:["'.date('Y-m').'-01T00:00:00Z" TO *]';
                break;

            case 't':
                $p['fq'][] = 'action_type:'.$id;
                break;

            case 'u':
                $p['fq'][] = 'user_id:'.$id;
                break;

            case 't':
                $p['fq'][] = 'action_type:'.$id;
                break;

        }

        $rez = $s->query($p);

        foreach ($rez['data'] as &$doc) {
            $k = @$doc['action_id'];
            $data = Util\toJSONArray($doc['object_data']);

            $doc['id'] = $this->getId($k);
            $doc['pid'] = @$doc['object_pid'];
            unset($doc['object_pid']);
            $doc['name'] = Util\coalesce($data['name'], $doc['object_data']);
            $doc['iconCls'] = $data['iconCls'];
            $doc['path'] = $data['path'];
            // $doc['template_id'] = $data['template_id'];
            $doc['case_id'] = $data['case_id'];
            if ($data['date']) {
                $doc['date'] = $data['date'];
            }
            $doc['size'] = $data['size'];
            $doc['cid'] = @$data['cid'];
            $doc['oid'] = @$data['oid'];
            $doc['uid'] = @$data['uid'];
            $doc['cdate'] = $data['cdate'];
            $doc['udate'] = $data['udate'];
            $doc['user'] = User::getDisplayName($doc['user_id'], true);
            $doc['has_childs'] = false;
        }

        return $rez;
    }
}
