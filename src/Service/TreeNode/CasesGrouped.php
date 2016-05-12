<?php

namespace Casebox\CoreBundle\Service\TreeNode;

use Casebox\CoreBundle\Service\Templates;
use Casebox\CoreBundle\Service\Cache;
use Casebox\CoreBundle\Service\Search;
use Casebox\CoreBundle\Service\Objects;
use Casebox\CoreBundle\Service\DataModel as DM;

class CasesGrouped extends Base
{
    protected $fq;

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
        if (parent::acceptedPath($pathArray, $requestParams)) {
            $lastNode = $pathArray[sizeof($pathArray) - 1];

            if ((sizeof($pathArray) > 1) &&
                (get_class($lastNode) != get_class($this))
            ) {
                return false;
            }
        }

        return true;

    }

    protected function createDefaultFilter()
    {

        $this->fq = [];

        //select only case templates
        $caseTemplates = DM\Templates::getIdsByType('case');
        if (!empty($caseTemplates)) {
            $this->fq[] = 'template_id:('.implode(' OR ', $caseTemplates).')';
        }
        $this->user_id = Cache::get('session')->get('user')['id'];
        $this->fq[] = sprintf('(role_ids1:%1$d OR role_ids2:%1$d OR role_ids3:%1$d)', $this->user_id);
    }

    public function getChildren(&$pathArray, $requestParams)
    {
        $this->path = $pathArray;
        $this->lastNode = @$pathArray[sizeof($pathArray) - 1];
        $this->requestParams = $requestParams;

        if (!$this->acceptedPath($pathArray, $requestParams)) {
            return;
        }

        $this->createDefaultFilter();

        $ourPid = @intval($this->config['pid']);

        if (empty($this->lastNode) || ($this->lastNode->id == $ourPid)) {
            $rez = $this->getRootNodes();
        } else {
            switch ($this->lastNode->id) {
                case 1:
                case 2:
                case 3:
                case 4:
                    $rez = $this->getDepthChildren2();
                    break;
                default:
                    $rez = $this->getCases();
            }
        }

        return $rez;
    }

    public function getName($id = false)
    {
        if ($id == false) {
            $id = $this->id;
        }

        switch ($id) {
            case 1:
                return $this->trans('Manager');
            case 2:
                return $this->trans('Lead');
            case 3:
                return $this->trans('Support');
            case 4:
                return $this->trans('AllMyCases');
        }

        return Objects::getName($id);
    }

    protected function getRootNodes()
    {
        $fq = $this->fq;
        $s = new Search();
        $sr = $s->query(
            [
                'rows' => 0,
                'fq' => $fq,
                'facet' => true,
                'facet.field' => [
                    '{!ex=role_ids1 key=manager}role_ids1',
                    '{!ex=role_ids2 key=lead}role_ids2',
                    '{!ex=role_ids3 key=support}role_ids3',
                ],
            ]
        );

        $rez = ['data' => []];

        if (!empty($sr['facets']->facet_fields->{'lead'}->{$this->user_id})) {
            $rez['data'][] = [
                'name' => $this->getName(2),
                'id' => $this->getId(2),
                'iconCls' => 'icon-folder',
                'has_childs' => true,
            ];
        }
        if (!empty($sr['facets']->facet_fields->{'support'}->{$this->user_id})) {
            $rez['data'][] = [
                'name' => $this->getName(3),
                'id' => $this->getId(3),
                'iconCls' => 'icon-folder',
                'has_childs' => true,
            ];
        }
        if (!empty($sr['facets']->facet_fields->{'manager'}->{$this->user_id})) {
            $rez['data'][] = [
                'name' => $this->getName(1),
                'id' => $this->getId(1),
                'iconCls' => 'icon-folder',
                'has_childs' => true,
            ];
        }
        if (!empty($sr['facets']->facet_fields->{'lead'}) || !empty($sr['facets']->facet_fields->{'support'})) {
            $rez['data'][] = [
                'name' => $this->getName(4),
                'id' => $this->getId(4),
                'iconCls' => 'i-magnifier',
                'has_childs' => true,
            ];
        }

        return $rez;
    }

    protected function getDepthChildren2()
    {
        $fq = $this->fq;
        switch ($this->lastNode->id) {
            case 4: //all my cases
                $fq[] = '(role_ids2:'.$this->user_id.' OR role_ids3:'.$this->user_id.')';
                break;
            default:
                $fq[] = 'role_ids'.$this->lastNode->id.':'.$this->user_id;
        }

        if (@$this->requestParams['from'] == 'tree') {
            $s = new Search();
            $sr = $s->query(
                [
                    'rows' => 0,
                    'fq' => $fq,
                    'facet' => true,
                    'facet.field' => [
                        '{!ex=status key=status}status',
                    ],
                ]
            );

            $rez = ['data' => []];
            if (!empty($sr['facets']->facet_fields->{'status'})) {
                foreach ($sr['facets']->facet_fields->{'status'} as $k => $v) {
                    $rez['data'][] = [
                        'name' => $this->getName($k).' ('.$v.')',
                        'id' => $this->getId($k),
                        'iconCls' => 'icon-folder',
                        'has_childs' => true,
                    ];
                }
            }

            return $rez;
        }

        // for other views
        $s = new Search();
        $rez = $s->query(['fq' => $fq]);

        return $rez;
    }

    protected function getCases()
    {
        $fq = $this->fq;

        $parent = $this->lastNode->parent;

        switch ($parent->id) {
            case 4: //all my cases
                $fq[] = '(role_ids2:'.$this->user_id.' OR role_ids3:'.$this->user_id.')';
                break;
            default:
                $fq[] = 'role_ids'.$parent->id.':'.$this->user_id;
        }

        $fq[] = 'status:'.$this->lastNode->id;

        $s = new Search();
        $rez = $s->query(['fq' => $fq]);

        return $rez;
    }
}
