<?php namespace Phpcmf\Controllers;

class Home extends \Phpcmf\App
{
    private $table;

    public function __construct(...$params)
    {
        parent::__construct($params);
        $dbprefix    = \Phpcmf\Service::M()->db->DBPrefix;
        $this->table = $dbprefix . SITE_ID . '_baoshu';
        $this->_ensure_table();
    }

    // 检查用户是否有访问权限
    private function _is_allowed(): bool
    {
        if (!$this->uid) return false;
        if (!empty($this->member['is_admin'])) return true;
        $gids = is_array($this->member['groupid'] ?? null) ? $this->member['groupid'] : [];
        return isset($gids[2]) || isset($gids[3]);
    }

    // 写log
    private function _write_log(string $line): void
    {
        $dir  = ROOTPATH . 'logs/';
        $file = $dir . 'baoshu_' . date('Y-m') . '.log';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
            file_put_contents($dir . '.htaccess', "Deny from all\n");
        }
        file_put_contents($file, $line . "\n", FILE_APPEND | LOCK_EX);
    }

    // 首次访问时自动建表
    private function _ensure_table(): void
    {
        $db = \Phpcmf\Service::M()->db;
        if (!$db->tableExists($this->table)) {
            $db->query("CREATE TABLE IF NOT EXISTS `{$this->table}` (
                `id`        int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
                `uid`       int(11) UNSIGNED NOT NULL DEFAULT 0,
                `username`  varchar(100)     NOT NULL DEFAULT '',
                `number`    int(11) UNSIGNED NOT NULL DEFAULT 0,
                `note`      text             NOT NULL,
                `archive_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
                `inputtime` int(11) UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (`id`),
                KEY `uid` (`uid`),
                KEY `archive_id` (`archive_id`),
                KEY `inputtime` (`inputtime`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='参与报数'");
        } else {
            $field = $db->query("SHOW COLUMNS FROM `{$this->table}` LIKE 'archive_id'")->getRowArray();
            if (!$field) {
                $db->query("ALTER TABLE `{$this->table}` ADD `archive_id` int(11) UNSIGNED NOT NULL DEFAULT 0 AFTER `note`");
                $db->query("ALTER TABLE `{$this->table}` ADD KEY `archive_id` (`archive_id`)");
            }
        }
        $cfg = $this->table . '_config';
        if (!$db->tableExists($cfg)) {
            $db->query("CREATE TABLE IF NOT EXISTS `{$cfg}` (
                `k` varchar(50)  NOT NULL,
                `v` varchar(255) NOT NULL DEFAULT '',
                PRIMARY KEY (`k`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }
        $archive = $this->table . '_archive';
        if (!$db->tableExists($archive)) {
            $db->query("CREATE TABLE IF NOT EXISTS `{$archive}` (
                `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
                `start_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
                `cutoff_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
                `cutoff_record_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
                `reason` varchar(500) NOT NULL DEFAULT '',
                `record_count` int(11) UNSIGNED NOT NULL DEFAULT 0,
                `archive_sum` bigint(20) UNSIGNED NOT NULL DEFAULT 0,
                `base_before` bigint(20) UNSIGNED NOT NULL DEFAULT 0,
                `base_after` bigint(20) UNSIGNED NOT NULL DEFAULT 0,
                `admin_uid` int(11) UNSIGNED NOT NULL DEFAULT 0,
                `admin_username` varchar(100) NOT NULL DEFAULT '',
                `inputtime` int(11) UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (`id`),
                KEY `cutoff_time` (`cutoff_time`),
                KEY `inputtime` (`inputtime`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='报数归档批次'");
        }
    }

    private function _cfg_get(string $key, string $default = '0'): string
    {
        $row = \Phpcmf\Service::M()->db
            ->table($this->table . '_config')
            ->where('k', $key)->get()->getRowArray();
        return $row ? $row['v'] : $default;
    }

    private function _cfg_set(string $key, string $value): void
    {
        $db  = \Phpcmf\Service::M()->db;
        $tbl = $this->table . '_config';
        $exists = $db->table($tbl)->where('k', $key)->countAllResults();
        if ($exists) {
            $db->table($tbl)->where('k', $key)->update(['v' => $value]);
        } else {
            $db->table($tbl)->insert(['k' => $key, 'v' => $value]);
        }
    }

    private function _archive_table(): string
    {
        return $this->table . '_archive';
    }

    private function _format_datetime(int $timestamp): string
    {
        return $timestamp > 0 ? date('Y-m-d H:i:s', $timestamp) : '';
    }

    private function _csv_cell($value): string
    {
        $value = (string) $value;
        if ($value !== '' && preg_match('/^[=+\-@]/', $value)) {
            $value = "'" . $value;
        }

        return $value;
    }

    private function _csv_row($fp, array $row): void
    {
        $safeRow = [];
        foreach ($row as $value) {
            $safeRow[] = $this->_csv_cell($value);
        }

        fputcsv($fp, $safeRow);
    }

    private function _load_archive_map(array $archiveIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $archiveIds))));
        if (!$ids) {
            return [];
        }

        $rows = \Phpcmf\Service::M()->db
            ->table($this->_archive_table())
            ->whereIn('id', $ids)
            ->get()->getResultArray();

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['id']] = $row;
        }

        return $map;
    }

    private function _attach_archive_info(array &$list): void
    {
        $archiveIds = [];
        foreach ($list as $row) {
            if (!empty($row['archive_id'])) {
                $archiveIds[] = (int) $row['archive_id'];
            }
        }

        $archiveMap = $this->_load_archive_map($archiveIds);
        foreach ($list as &$row) {
            $archive = !empty($row['archive_id']) && isset($archiveMap[(int) $row['archive_id']])
                ? $archiveMap[(int) $row['archive_id']]
                : null;
            $row['archive_reason'] = $archive ? $archive['reason'] : '';
            $row['archive_time'] = $archive ? (int) $archive['inputtime'] : 0;
            $row['archive_username'] = $archive ? $archive['admin_username'] : '';
        }
        unset($row);
    }

    // 数字转"30亿6670万7945"混合格式
    private function _to_mixed(int $n): string
    {
        if ($n <= 0) return '0';
        $yi  = (int)floor($n / 100000000);
        $wan = (int)floor(($n % 100000000) / 10000);
        $ge  = $n % 10000;

        if ($yi > 0) {
            $result = $yi . '亿';
            if ($wan > 0 || $ge > 0) {
                $result .= str_pad((string)$wan, 4, '0', STR_PAD_LEFT) . '万';
            }
            if ($ge > 0) {
                $result .= str_pad((string)$ge, 4, '0', STR_PAD_LEFT);
            }
            return $result;
        }
        if ($wan > 0) {
            $result = $wan . '万';
            if ($ge > 0) $result .= str_pad((string)$ge, 4, '0', STR_PAD_LEFT);
            return $result;
        }
        return (string)$ge ?: '0';
    }

    // 整数转汉字大写（支持到千亿级）
    private function _to_chinese(int $n): string
    {
        if ($n === 0) return '零';

        $digits      = ['零','一','二','三','四','五','六','七','八','九'];
        $units4      = ['','十','百','千'];
        $unitSection = ['','万','亿'];

        $convertSection = function (int $num) use ($digits, $units4): string {
            $result = '';
            $zero   = false;
            for ($i = 3; $i >= 0; $i--) {
                $d = (int) floor($num / pow(10, $i)) % 10;
                if ($d === 0) {
                    $zero = true;
                } else {
                    if ($zero && $result !== '') $result .= '零';
                    $zero    = false;
                    $result .= $digits[$d] . $units4[$i];
                }
            }
            return $result;
        };

        $sections = [];
        $tmp      = $n;
        while ($tmp > 0) {
            $sections[] = $tmp % 10000;
            $tmp        = (int) floor($tmp / 10000);
        }

        $result = '';
        $cnt    = count($sections);
        for ($i = $cnt - 1; $i >= 0; $i--) {
            $str = $convertSection($sections[$i]);
            if ($str) {
                $result .= $str . $unitSection[$i];
                // 下一节不足四位时补零（如：一万零一）
                if ($i > 0 && $sections[$i - 1] > 0 && $sections[$i - 1] < 1000) {
                    $result .= '零';
                }
            }
        }

        // 十～十九省略开头的"一"（标准写法：十三 而非 一十三）
        return preg_replace('/^一十/', '十', $result) ?: '零';
    }

    public function index()
    {
        $db = \Phpcmf\Service::M()->db;

        // ── AJAX 提交处理 ──────────────────────────────────────────────
        if (IS_POST) {
            if (!$this->uid) {
                $this->_json(0, '请先登录后再报数');
            }
            $gids    = is_array($this->member['groupid'] ?? null) ? $this->member['groupid'] : [];
            $isAdmin = !empty($this->member['is_admin']);
            if (!$isAdmin && !isset($gids[3])) {
                $this->_json(0, '需要加入报数群组后才能报数');
            }

            $number = intval(\Phpcmf\Service::L('input')->post('number'));
            $note   = trim(strip_tags(\Phpcmf\Service::L('input')->post('note')));

            if ($number <= 0) {
                $this->_json(0, '数量必须大于零');
            }
            if ($number > 100000000) {
                $this->_json(0, '单次最多提交1亿');
            }
            if ($note === '') {
                $this->_json(0, '请填写内容描述');
            }
            if (mb_strlen($note) > 10000) {
                $this->_json(0, '内容不超过10000字');
            }

            $username = $this->member['username'] ?? ('用户' . $this->uid);
            $db->table($this->table)->insert([
                'uid'       => $this->uid,
                'username'  => $username,
                'number'    => $number,
                'note'      => $note,
                'inputtime' => SYS_TIME,
            ]);

            $this->_write_log(sprintf(
                "[%s] uid=%d user=%s number=%d note=%s",
                date('Y-m-d H:i:s'),
                $this->uid,
                $username,
                $number,
                mb_substr($note, 0, 200)
            ));

            $this->_json(1, '报数成功');
        }

        // ── 无权限用户直接渲染空页面 ───────────────────────────────────
        if (!$this->_is_allowed()) {
            \Phpcmf\Service::V()->assign([
                'meta_title' => '参与报数',
                'is_login'   => $this->uid ? 1 : 0,
                'has_access' => 0,
                'my_uid'     => 0,
                'is_admin'   => 0,
            ]);
            \Phpcmf\Service::V()->display('index.html');
            return;
        }

        // ── 读取归档基数 ───────────────────────────────────────────────
        $baseCount = (int) $this->_cfg_get('base_count', '0');
        $baseTime  = (int) $this->_cfg_get('base_time',  '0');

        // ── 分页参数 ───────────────────────────────────────────────────
        $perPage   = 20;
        $page      = max(1, (int) \Phpcmf\Service::L('input')->get('page'));
        $cntQuery  = $db->table($this->table);
        if ($baseTime > 0) $cntQuery->where('inputtime >', $baseTime);
        $totalRows = $cntQuery->countAllResults();
        $totalPages = max(1, (int) ceil($totalRows / $perPage));
        $page       = min($page, $totalPages);

        // ── 查询列表（仅归档后的记录，分页）──────────────────────────
        $listQuery = $db->table($this->table)->orderBy('inputtime', 'DESC')
            ->limit($perPage, ($page - 1) * $perPage);
        if ($baseTime > 0) $listQuery->where('inputtime >', $baseTime);
        $list = $listQuery->get()->getResultArray();

        foreach ($list as &$row) {
            $row['number_mixed'] = $this->_to_mixed((int) $row['number']);
        }
        unset($row);
        $this->_attach_archive_info($list);

        // 统计总数 = 基数 + 归档后新记录之和
        $sumQuery = $db->table($this->table)->selectSum('number');
        if ($baseTime > 0) $sumQuery->where('inputtime >', $baseTime);
        $sumRow   = $sumQuery->get()->getRow();
        $newSum   = $sumRow ? (int) $sumRow->number : 0;
        $total    = (int) ($baseCount + $newSum);

        // 本月统计（归档后且在本月内）
        $monthStart = mktime(0, 0, 0, (int)date('n'), 1, (int)date('Y'));
        $mStart     = max($monthStart, $baseTime > 0 ? $baseTime + 1 : 0);
        $mQuery     = $db->table($this->table)->selectSum('number')->where('inputtime >=', $mStart);
        $mRow       = $mQuery->get()->getRow();
        $monthTotal = $mRow ? (int) $mRow->number : 0;

        $isAdmin = $this->member && !empty($this->member['is_admin']);

        \Phpcmf\Service::V()->assign([
            'meta_title'   => '参与报数',
            'list'         => $list,
            'total'        => $total,
            'total_mixed'  => $this->_to_mixed($total),
            'month_total'  => $this->_to_mixed($monthTotal),
            'month_name'   => date('Y') . '年' . date('n') . '月',
            'is_login'     => 1,
            'has_access'   => 1,
            'my_uid'       => (int) $this->uid,
            'is_admin'     => $isAdmin ? 1 : 0,
            'page'         => $page,
            'total_pages'  => $totalPages,
            'page_url'     => 'index.php?s=baoshu&c=home&m=index&page=',
        ]);

        \Phpcmf\Service::V()->display('index.html');
    }

    public function history()
    {
        if (!$this->_is_allowed()) {
            $this->_json(0, '无权限访问');
        }

        $db        = \Phpcmf\Service::M()->db;
        $baseTime  = (int) $this->_cfg_get('base_time', '0');

        if ($baseTime <= 0) {
            \Phpcmf\Service::V()->assign([
                'meta_title'  => '历史记录',
                'list'        => [],
                'page'        => 1,
                'total_pages' => 1,
                'page_url'    => 'index.php?s=baoshu&c=home&m=history&page=',
                'is_admin'    => !empty($this->member['is_admin']) ? 1 : 0,
                'my_uid'      => (int) $this->uid,
            ]);
            \Phpcmf\Service::V()->display('history.html');
            return;
        }

        $perPage    = 20;
        $page       = max(1, (int) \Phpcmf\Service::L('input')->get('page'));
        $totalRows  = $db->table($this->table)->where('inputtime <=', $baseTime)->countAllResults();
        $totalPages = max(1, (int) ceil($totalRows / $perPage));
        $page       = min($page, $totalPages);

        $list = $db->table($this->table)
            ->where('inputtime <=', $baseTime)
            ->orderBy('inputtime', 'DESC')
            ->limit($perPage, ($page - 1) * $perPage)
            ->get()->getResultArray();

        foreach ($list as &$row) {
            $row['number_mixed'] = $this->_to_mixed((int) $row['number']);
        }
        unset($row);
        $this->_attach_archive_info($list);

        \Phpcmf\Service::V()->assign([
            'meta_title'  => '历史记录',
            'list'        => $list,
            'page'        => $page,
            'total_pages' => $totalPages,
            'page_url'    => 'index.php?s=baoshu&c=home&m=history&page=',
            'is_admin'    => !empty($this->member['is_admin']) ? 1 : 0,
            'my_uid'      => (int) $this->uid,
        ]);

        \Phpcmf\Service::V()->display('history.html');
    }

    public function archive()
    {
        if (!$this->uid || empty($this->member['is_admin'])) {
            $this->_json(0, '无权限');
        }

        $db = \Phpcmf\Service::M()->db;

        $recordId = intval(\Phpcmf\Service::L('input')->post('record_id'));
        $reason   = trim(strip_tags(\Phpcmf\Service::L('input')->post('reason')));

        if ($reason === '') {
            $this->_json(0, '请填写归档原因');
        }
        if (mb_strlen($reason) > 500) {
            $this->_json(0, '归档原因不超过500字');
        }

        // 确定归档截止时间
        if ($recordId > 0) {
            $record = $db->table($this->table)->where('id', $recordId)->get()->getRowArray();
            if (!$record) {
                $this->_json(0, '记录不存在');
            }
            $cutoffTime = (int) $record['inputtime'];
        } else {
            $cutoffTime = SYS_TIME;
        }

        // 读取当前基数和已有归档时间
        $oldBase = (int) $this->_cfg_get('base_count', '0');
        $oldTime = (int) $this->_cfg_get('base_time',  '0');

        if ($cutoffTime <= $oldTime) {
            $this->_json(0, '所选记录已在归档范围内');
        }

        // 累加 oldTime < inputtime <= cutoffTime 的记录
        $q = $db->table($this->table)->selectSum('number')->selectCount('id', 'record_count')
            ->where('inputtime <=', $cutoffTime);
        if ($oldTime > 0) $q->where('inputtime >', $oldTime);
        $row         = $q->get()->getRow();
        $newSum      = $row ? (int) $row->number : 0;
        $recordCount = $row ? (int) $row->record_count : 0;

        if ($recordCount <= 0 || $newSum <= 0) {
            $this->_json(0, '没有可归档记录');
        }

        $newBase = (int) ($oldBase + $newSum);

        $archiveData = [
            'start_time'       => $oldTime > 0 ? $oldTime + 1 : 0,
            'cutoff_time'      => $cutoffTime,
            'cutoff_record_id' => $recordId,
            'reason'           => $reason,
            'record_count'     => $recordCount,
            'archive_sum'      => $newSum,
            'base_before'      => $oldBase,
            'base_after'       => $newBase,
            'admin_uid'        => (int) $this->uid,
            'admin_username'   => $this->member['username'] ?? ('用户' . $this->uid),
            'inputtime'        => SYS_TIME,
        ];
        $db->table($this->_archive_table())->insert($archiveData);
        $archiveId = (int) $db->insertID();
        if (!$archiveId) {
            $latest = $db->table($this->_archive_table())
                ->where('admin_uid', (int) $this->uid)
                ->where('inputtime', SYS_TIME)
                ->orderBy('id', 'DESC')
                ->get()->getRowArray();
            $archiveId = $latest ? (int) $latest['id'] : 0;
        }
        if (!$archiveId) {
            $this->_json(0, '归档批次创建失败');
        }

        $update = $db->table($this->table)->where('inputtime <=', $cutoffTime);
        if ($oldTime > 0) $update->where('inputtime >', $oldTime);
        $update->update(['archive_id' => $archiveId]);

        $this->_cfg_set('base_count', (string) $newBase);
        $this->_cfg_set('base_time',  (string) $cutoffTime);

        $this->_write_log(sprintf(
            "[%s] ARCHIVE uid=%d user=%s archive_id=%d record_count=%d sum=%d reason=%s",
            date('Y-m-d H:i:s'),
            $this->uid,
            $this->member['username'] ?? ('用户' . $this->uid),
            $archiveId,
            $recordCount,
            $newSum,
            mb_substr($reason, 0, 200)
        ));

        $this->_json(1, '归档成功，新基数：' . $this->_to_mixed($newBase));
    }

    public function export_csv()
    {
        if (!$this->uid || empty($this->member['is_admin'])) {
            $this->_json(0, '无权限');
        }

        $db = \Phpcmf\Service::M()->db;

        $baseCount = (int) $this->_cfg_get('base_count', '0');
        $baseTime  = (int) $this->_cfg_get('base_time', '0');

        $sumQuery = $db->table($this->table)->selectSum('number');
        if ($baseTime > 0) {
            $sumQuery->where('inputtime >', $baseTime);
        }
        $sumRow = $sumQuery->get()->getRow();
        $newSum = $sumRow ? (int) $sumRow->number : 0;
        $total  = $baseCount + $newSum;

        $totalRows = $db->table($this->table)->countAllResults();
        $list = $db->table($this->table)
            ->orderBy('inputtime', 'DESC')
            ->orderBy('id', 'DESC')
            ->get()->getResultArray();
        $this->_attach_archive_info($list);

        $latestArchive = $db->table($this->_archive_table())
            ->orderBy('inputtime', 'DESC')
            ->limit(1)
            ->get()->getRowArray();

        $filename = 'baoshu_export_' . date('Y-m-d') . '.csv';

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');

        $fp = fopen('php://output', 'w');
        if (!$fp) {
            exit;
        }

        fwrite($fp, "\xEF\xBB\xBF");

        $this->_csv_row($fp, ['报数导出报表']);
        $this->_csv_row($fp, ['导出日期', date('Y-m-d')]);
        $this->_csv_row($fp, ['导出时间', date('Y-m-d H:i:s')]);
        $this->_csv_row($fp, ['导出人', $this->member['username'] ?? ('用户' . $this->uid)]);
        $this->_csv_row($fp, ['放生总数', (string) $total]);
        $this->_csv_row($fp, ['放生总数显示', $this->_to_mixed($total) . '位']);
        $this->_csv_row($fp, ['归档基数', (string) $baseCount]);
        $this->_csv_row($fp, ['归档截止时间', $this->_format_datetime($baseTime)]);
        $this->_csv_row($fp, ['归档后新增合计', (string) $newSum]);
        $this->_csv_row($fp, ['明细记录数', (string) $totalRows]);
        $this->_csv_row($fp, ['最新归档时间', $latestArchive ? $this->_format_datetime((int) $latestArchive['inputtime']) : '']);
        $this->_csv_row($fp, ['最新归档原因', $latestArchive ? $latestArchive['reason'] : '']);
        $this->_csv_row($fp, ['最新归档人', $latestArchive ? $latestArchive['admin_username'] : '']);
        $this->_csv_row($fp, []);
        $this->_csv_row($fp, ['提交明细']);
        $this->_csv_row($fp, ['ID', '状态', '提交时间', '用户ID', '用户名', '数量', '数量显示', '归档时间', '归档原因', '归档人', '内容描述']);

        foreach ($list as $row) {
            $isArchived = $baseTime > 0 && (int) $row['inputtime'] <= $baseTime;
            $this->_csv_row($fp, [
                (string) $row['id'],
                $isArchived ? '已归档' : '当前',
                $this->_format_datetime((int) $row['inputtime']),
                (string) $row['uid'],
                $row['username'],
                (string) $row['number'],
                $this->_to_mixed((int) $row['number']) . '位',
                $this->_format_datetime((int) $row['archive_time']),
                $row['archive_reason'],
                $row['archive_username'],
                $row['note'],
            ]);
        }

        fclose($fp);
        exit;
    }

    public function changepassword()
    {
        if (!$this->uid) {
            $this->_json(0, '请先登录');
        }

        $old_pwd = trim(\Phpcmf\Service::L('input')->post('old_pwd'));
        $new_pwd = trim(\Phpcmf\Service::L('input')->post('new_pwd'));

        if (!$old_pwd || !$new_pwd) {
            $this->_json(0, '参数错误');
        }
        if (mb_strlen($new_pwd) < 6) {
            $this->_json(0, '新密码至少6位');
        }

        $dbprefix = \Phpcmf\Service::M()->db->DBPrefix;
        $db       = \Phpcmf\Service::M()->db;
        $member   = $db->table($dbprefix . 'member')
            ->where('id', $this->uid)
            ->get()->getRowArray();

        if (!$member) {
            $this->_json(0, '用户不存在');
        }

        // 验证旧密码（兼容新旧两种哈希格式）
        $hash_new = md5(md5($old_pwd) . $member['salt'] . md5($old_pwd));
        $hash_old = md5($old_pwd . $member['salt'] . $old_pwd);
        if ($hash_new !== $member['password'] && $hash_old !== $member['password']) {
            $this->_json(0, '当前密码不正确');
        }

        $new_hash = md5(md5($new_pwd) . $member['salt'] . md5($new_pwd));

        $db->table($dbprefix . 'member')
            ->where('id', $this->uid)
            ->update(['password' => $new_hash]);

        $this->_json(1, '密码修改成功');
    }

    public function delete()
    {
        if (!$this->uid) {
            $this->_json(0, '请先登录');
        }

        $id = intval(\Phpcmf\Service::L('input')->post('id'));
        if (!$id) {
            $this->_json(0, '参数错误');
        }

        $db     = \Phpcmf\Service::M()->db;
        $record = $db->table($this->table)->where('id', $id)->get()->getRowArray();

        if (!$record) {
            $this->_json(0, '记录不存在');
        }
        if (empty($this->member['is_admin'])) {
            $this->_json(0, '无权删除');
        }

        $db->table($this->table)->where('id', $id)->delete();
        $this->_write_log(sprintf(
            "[%s] DELETE uid=%d user=%s record_id=%d orig_uid=%d number=%d note=%s",
            date('Y-m-d H:i:s'),
            $this->uid,
            $this->member['username'] ?? ('用户' . $this->uid),
            $id,
            $record['uid'],
            $record['number'],
            mb_substr($record['note'], 0, 200)
        ));
        $this->_json(1, '删除成功');
    }

    public function edit()
    {
        if (!$this->uid) {
            $this->_json(0, '请先登录');
        }

        $id     = intval(\Phpcmf\Service::L('input')->post('id'));
        $number = intval(\Phpcmf\Service::L('input')->post('number'));
        $note   = trim(strip_tags(\Phpcmf\Service::L('input')->post('note')));

        if (!$id) {
            $this->_json(0, '参数错误');
        }
        if ($number <= 0) {
            $this->_json(0, '数量必须大于零');
        }
        if ($number > 100000000) {
            $this->_json(0, '单次最多提交一亿');
        }
        if ($note === '') {
            $this->_json(0, '请填写内容描述');
        }
        if (mb_strlen($note) > 10000) {
            $this->_json(0, '内容不超过10000字');
        }

        $db     = \Phpcmf\Service::M()->db;
        $record = $db->table($this->table)->where('id', $id)->get()->getRowArray();

        if (!$record) {
            $this->_json(0, '记录不存在');
        }
        $isAdmin = !empty($this->member['is_admin']);
        if (!$isAdmin && (int) $record['uid'] !== (int) $this->uid) {
            $this->_json(0, '只能修改自己的记录');
        }

        $db->table($this->table)->where('id', $id)->update([
            'number' => $number,
            'note'   => $note,
        ]);
        $this->_write_log(sprintf(
            "[%s] EDIT uid=%d user=%s record_id=%d orig_number=%d->%d note=%s",
            date('Y-m-d H:i:s'),
            $this->uid,
            $this->member['username'] ?? ('用户' . $this->uid),
            $id,
            $record['number'],
            $number,
            mb_substr($note, 0, 200)
        ));
        $this->_json(1, '修改成功');
    }
}
