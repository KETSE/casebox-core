<?php

namespace Casebox\CoreBundle\Service\Objects\Plugins;

use Casebox\CoreBundle\Service\Util;

/**
 * Class Files
 */
class Files extends Base
{
    public function getData($id = false)
    {

        $rez = [
            'success' => true,
            'data' => [],
        ];

        $prez = parent::getData($id);
        if (empty($prez)) {
            return $this->isVisible() ? $rez : null;
        }

        $params = [
            'pid' => $this->id,
            'fq' => [
                '(template_type:file) OR (target_type:file)',
            ],
            'fl' => 'id,pid,name,template_id,size,cdate',
            'sort' => 'cdate',
            'dir' => 'desc',
        ];

        $s = new \Casebox\CoreBundle\Service\Search();
        $sr = $s->query($params);
        foreach ($sr['data'] as $d) {
            $d['ago_text'] = Util\formatAgoTime($d['cdate']);
            $rez['data'][] = $d;
        }

        return $rez;
    }
}
