<?php

namespace Export;

use Casebox\CoreBundle\Service\BrowserView;
use Casebox\CoreBundle\Service\Cache;
use Casebox\CoreBundle\Service\Util;
use Casebox\CoreBundle\Service\User;

class Instance
{
    public function install()
    {

    }

    public function init()
    {
    }

    protected function getData($p)
    {
        $rez = [];
        if (empty($p)) {
            return $rez;
        }

        $configService = Cache::get('symfony.container')->get('casebox_core.service.config');
        // form columns
        $defaultColumns = $configService->getDefaultGridColumnConfigs();
        $columns = $defaultColumns;

        // retreive data
        $p['start'] = 0;
        $p['rows'] = 500;

        $sr = new BrowserView();
        $results = $sr->getChildren($p);

        if (!empty($results['DC'])) {
            $columns = [];

            foreach ($results['DC'] as $colName => $col) {
                if (@$col['hidden'] !== true) {
                    $columns[$colName] = $col;
                }
            }
        }

        $colTitles = [];
        foreach ($columns as $name => $col) {
            $colTitles[] = empty($defaultColumns[$name]) ? @Util\coalesce($col['title'], $name) : $defaultColumns[$name]['title'];
        }

        //insert header
        $rez[] = $colTitles;

        while (!empty($results['data'])) {
            foreach ($results['data'] as $r) {
                $record = [];
                foreach ($columns as $colName => $col) {

                    if (@$col['xtype'] == 'datecolumn') {
                        $value = Util\dateISOToMysql(@$r[$colName]);

                        if (!empty($col['format'])) {
                            $value = Util\formatMysqlTime($value, $col['format']);

                        } else {
                            $value = Util\formatMysqlTime($value);
                            $tmp = explode(' ', $value);
                            if (!empty($tmp[1]) && ($tmp[1] == '00:00')) {
                                $value = $tmp[0];
                            }
                        }
                        $record[] = $value;

                    } elseif (strpos($colName, 'date') === false) {
                        if (in_array($colName, ['oid', 'cid', 'uid']) && !empty($r[$colName])) {
                            $record[] = User::getDisplayName($r[$colName]);
                        } else {
                            $record[] = @$r[$colName];
                        }
                    }

                }
                $rez[] = $record;
            }

            if (($p['start'] + $p['rows']) < $results['total']) {
                $p['start'] += $p['rows'];
                $results = $sr->getChildren($p);
            } else {
                $results['data'] = [];
            }
        }

        return $rez;
    }

    /**
     * get csv file
     *
     * @param $p object
     */
    public function getCSV($p)
    {
        $rez = [];
        $records = $this->getData($p);

        $rez[] = implode(';', array_shift($records));

        foreach ($records as &$r) {
            $record = [];
            foreach ($r as $t) {
                $t = strip_tags($t);

                if (!empty($t) && !is_numeric($t)) {
                    $t = str_replace(
                        [
                            '"',
                            "\n",
                            "\r",
                        ],
                        [
                            '""',
                            '\n',
                            '\r',
                        ],
                        $t
                    );
                    $t = '"'.$t.'"';
                }
                $record[] = $t;
            }

            $rez[] = implode(';', $record);
        }
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=Exported_Results_'.date('Y-m-d_Hi').'.csv');
        header("Pragma: no-cache");
        header("Expires: 0");
        echo implode("\n", $rez);
    }

    public function getHTML($p)
    {
        $rez = [];
        $records = $this->getData($p);

        $rez[] = '<th>'.implode('</th><th>', array_shift($records)).'</th>';

        foreach ($records as $r) {
            $record = [];
            foreach ($r as $t) {
                $t = strip_tags($t);
                $record[] = $t;
            }
            $rez[] = '<td>'.implode('</td><td>', $record).'</td>';
        }

        header('Content-Type: text/html; charset=utf-8');
        header('Content-Disposition: attachment; filename=Exported_Results_'.date('Y-m-d_Hi').'.html');
        header("Pragma: no-cache");
        header("Expires: 0");
        echo '<!DOCTYPE html>
            <html>
            <header>
                <meta http-equiv="content-type" content="text/html; charset=utf-8" >
            </header>
            <body>
            <table border="1" style="border-collapse: collapse">
            <tr>';
        echo implode("</tr>\n<tr>", $rez);
        echo '</tr></table></body></html>';
    }
}
