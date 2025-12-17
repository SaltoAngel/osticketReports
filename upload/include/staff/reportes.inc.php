<?php
if (!isset($report) || !($report instanceof OverviewReport)) {
    $report = new OverviewReport($_POST['start'] ?? null, $_POST['period'] ?? null);
}

$groups = $report->enumTabularGroups();
$selectedGroup = $_POST['group'] ?? key($groups);
if (!$selectedGroup || !isset($groups[$selectedGroup])) {
    $selectedGroup = key($groups);
}

$data = $selectedGroup ? $report->getTabularData($selectedGroup) : null;
$range = array();
$timezone = $cfg->getTimezone();
foreach ($report->getDateRange() as $date) {
    $date = str_ireplace('FROM_UNIXTIME(', '', $date);
    $date = str_ireplace(')', '', $date);
    $date = new DateTime('@' . $date);
    $date->setTimeZone(new DateTimeZone($cfg->getTimezone()));
    $timezone = $date->format('e');
    $range[] = $date->format('F j, Y');
}

?><link rel="stylesheet" type="text/css" href="css/dashboard.css?0375576"/>

<form method="post" action="reportes.php" class="inline-form">
<div id="basic_search">
    <div style="min-height:25px;">
        <?php echo csrf_token(); ?>
        <label>
            <?php echo __('Report timeframe'); ?>:
            <input type="text" class="dp input-medium search-query" name="start" placeholder="<?php echo __('Last month'); ?>" value="<?php echo Format::htmlchars($report->getStartDate()); ?>" />
        </label>
        <label>
            <?php echo __('period'); ?>:
            <select name="period">
                <?php foreach ($report::$end_choices as $val => $desc) {
                    $selected = ($report->end == $val) ? 'selected="selected"' : '';
                    echo "<option value=\"$val\" $selected>" . __($desc) . '</option>';
                } ?>
            </select>
        </label>
        <label>
            <?php echo __('Group by'); ?>:
            <select name="group">
                <?php foreach ($groups as $key => $desc) {
                    $selected = ($key === $selectedGroup) ? 'selected="selected"' : '';
                    echo "<option value=\"" . Format::htmlchars($key) . "\" $selected>" . Format::htmlchars($desc) . '</option>';
                } ?>
            </select>
        </label>
        <button class="green button action-button muted" type="submit">
            <?php echo __('Refresh'); ?>
        </button>
    </div>
</div>

<div class="clear"></div>
<div style="margin:15px 0;">
    <div class="pull-left flush-left">
        <h2><?php echo __('Advanced Reports'); ?></h2>
        <p class="muted">
            <b><?php echo __('Range: '); ?></b>
            <?php
            if (count($range) >= 2) {
                echo __($range[0] . ' - ' . $range[1] . ' (' . Format::timezone($timezone) . ')');
            } else {
                echo __('Range not available');
            }
            ?>
        </p>
    </div>
</div>

<div class="clear"></div>
<?php if ($data && !empty($data['columns'])) { ?>
    <table class="dashboard-stats table"><tbody><tr>
        <?php foreach ($data['columns'] as $index => $column) { ?>
            <th <?php if ($index === 0) { echo 'width="30%" class="flush-left"'; } ?>><?php echo Format::htmlchars($column); ?></th>
        <?php } ?>
    </tr></tbody>
    <tbody>
        <?php foreach ($data['data'] as $row) {
            echo '<tr>';
            foreach ($row as $index => $cell) {
                if ($index === 0) { ?>
                    <th class="flush-left"><?php echo Format::htmlchars($cell); ?></th>
                <?php } else { ?>
                    <td><?php echo Format::htmlchars($cell); ?></td>
                <?php }
            }
            echo '</tr>';
        } ?>
    </tbody></table>
    <div style="margin-top: 10px;">
        <button type="submit" class="link button" name="export" value="<?php echo Format::htmlchars($selectedGroup); ?>">
            <i class="icon-download"></i>
            <?php echo __('Export'); ?>
        </button>
    </div>
<?php } else { ?>
    <p class="muted flush-left" style="margin-top:10px;">
        <?php echo __('No data available for the selected range.'); ?>
    </p>
<?php } ?>
</form>
