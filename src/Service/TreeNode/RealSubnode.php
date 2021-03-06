<?php

namespace Casebox\CoreBundle\Service\TreeNode;

class RealSubnode extends Base
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
        $lastNode = null;

        if (!empty($pathArray)) {
            $lastNode = $pathArray[sizeof($pathArray) - 1];
        }

        if (((empty($this->config['pid']) || (@$this->config['pid'] == '0')) && empty($lastNode)) ||
            (!empty($lastNode) && (@$this->config['pid'] == $lastNode->id))
        ) {
            return true;
        }

        return false;
    }

    public function getChildren(&$pathArray, $requestParams)
    {
        $this->path = $pathArray;
        $this->lastNode = @$pathArray[sizeof($pathArray) - 1];
        $this->requestParams = $requestParams;

        if (!$this->acceptedPath($pathArray, $requestParams)) {
            return;
        }
        /* should start with path check and see if child request is for a real db node*/

        $rez = [
            'data' => [
                [
                    'name' => $this->config['title'],
                    'id' => $this->config['realNodeId'],
                    'iconCls' => 'icon-folder',
                    'has_childs' => true,
                ],
            ],
        ];

        return $rez;
    }

    public function getName($id = false)
    {
        return $this->config['title'];
    }
}
