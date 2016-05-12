<?php

namespace Casebox\CoreBundle\Service\DataModel;

use Casebox\CoreBundle\Service\Cache;
use Casebox\CoreBundle\Service\Util;

/**
 * Class TemplatesStructure
 */
class TemplatesStructure extends Base
{
    /**
     * database table name
     * @var string
     */
    protected static $tableName = 'templates_structure';

    /**
     * available table fields
     *
     * associative array of fieldName => type
     * that is also used for trivial validation of input values
     *
     * @var array
     */
    protected static $tableFields = [
        'id' => 'int',
        'pid' => 'int',
        'template_id' => 'int',
        // ,'tag' => 'varchar' //obsolete,
        'level' => 'int',
        'name' => 'varchar',
        'type' => 'varchar',
        'order' => 'int',
        'cfg' => 'text',
        'solr_column_name' => 'varchar',
    ];

    protected static $decodeJsonFields = ['cfg'];

    /**
     * get only active (not deleted fields) for given template
     *
     * @param  int $templateId optional, filter by a template
     * @param  bool $onlyActive to return only active (nit deleted fields)
     *
     * @return array
     */
    public static function getFields($templateId = false, $onlyActive = true)
    {
        $rez = [];

        $dbs = Cache::get('casebox_dbs');

        $sql = 'SELECT
                ts.id
                ,ts.pid
                ,ts.template_id
                ,ts.name
                ,ts.`level`
                ,ts.`type`
                ,ts.cfg
                ,ts.order
                ,ts.solr_column_name
                ,o.data

            FROM templates_structure ts
            LEFT JOIN objects o ON ts.id = o.id ';

        if ($onlyActive) {
            $sql .= 'JOIN tree t on ts.id = t.id AND t.dstatus = 0 ';
        }

        if (is_numeric($templateId)) {
            $sql .= 'WHERE ts.template_id = $1 ';
        }

        $sql .= 'ORDER BY ts.template_id, ts.`order` ';

        $res = $dbs->query($sql, $templateId);

        while ($r = $res->fetch()) {
            $data = Util\toJSONArray($r['data']);
            unset($r['data']);

            //overwrite fields from templates table with values from objects.data
            $r = array_merge($r, $data);
            $r['cfg'] = Util\toJSONArray($r['cfg']);

            $r['title'] = Util\detectTitle($r);

            $rez[] = $r;
        }
        unset($res);

        return $rez;
    }

    public static function copy($sourceId, $targetId, $parentTemplate)
    {
        $dbs = Cache::get('casebox_dbs');

        $dbs->query(
            'INSERT INTO `templates_structure`
                (`id`
                ,`pid`
                ,`template_id`
                ,`name`
                ,`l1`
                ,`l2`
                ,`l3`
                ,`l4`
                ,`type`
                ,`order`
                ,`cfg`
                ,`solr_column_name`
                )
            SELECT
                t.id
                ,t.pid
                ,$3
                ,ts.name
                ,ts.l1
                ,ts.l2
                ,ts.l3
                ,ts.l4
                ,ts.type
                ,ts.order
                ,ts.cfg
                ,ts.solr_column_name
            FROM `tree` t
                ,templates_structure ts
            WHERE t.id = $2
                AND ts.id = $1
            ON DUPLICATE KEY UPDATE
                pid = t.pid
                ,template_id = $3
                ,name = ts.name
                ,l1 = ts.l1
                ,l2 = ts.l2
                ,l3 = ts.l3
                ,l4 = ts.l4
                ,`type` = ts.type
                ,`order` = ts.order
                ,`cfg` = ts.cfg
                ,solr_column_name = ts.solr_column_name',
            [
                $sourceId,
                $targetId,
                $parentTemplate,
            ]
        );
    }

    public static function move($sourceId, $targetId)
    {
        $dbs = Cache::get('casebox_dbs');

        $dbs->query(
            'UPDATE templates_structure
            SET pid = $2
            WHERE id = $1',
            [
                $sourceId,
                $targetId,
            ]
        );
    }
}
