<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0,maximum-scale=1.0, user-scalable=no"/>
    <title></title>
    <link rel="stylesheet" href="https://cdn.bootcss.com/bootstrap/3.3.7/css/bootstrap.min.css" integrity="sha384-BVYiiSIFeK1dGmJRAkycuHAHRg32OmUcww7on3RYdg4Va+PmSTsz/K68vbdEjh4u" crossorigin="anonymous">
    <!--		<script src="http://cdn.bootcss.com/jquery/2.1.4/jquery.min.js"></script>-->
    <!--        <script src="http://cdn.hcharts.cn/highcharts/highcharts.js"></script>-->
    <style>
        *{margin:0;padding: 0}
    </style>
</head>
<body>
<div class="container">
    <div class="col-md-12 column text-center">
        <?php for ($i=1;$i<=$today;$i++){?>
            <?php
            $a_timestamp = strtotime($year.'-'.$month.'-'.$i);
            $a_time=date('Y-m-d',$a_timestamp);
            ?>
            <a href="/?day=<?php echo $a_time?>" class="btn" type="button"><?php if($a_time==$day){echo '<b>'.$a_time.'</b>';}else{echo $a_time;}?></a>
            <?php if ($i%7==0){echo '<br>';}?>
        <?php }?>

    </div>
    <div>
        <p>日期：<?php echo $day?></p>
        <p>日志总数：<?php echo $all['count']?></p>
        <p>平均时间：<?php echo $all['count']?round($all['all_run_time']/$all['count'],6):''?></p>
        <p>耗时最长请求：<?php echo $all['longest']['method']?></p>
        <p>耗时最长请求运行时间：<?php echo $all['longest']['run_time']?></p>
    </div>
    <table class="table table-bordered">
        <thead>
        <tr>
            <th>状态</th>
            <th>调用总数</th>
            <th>占比</th>
            <th>平均耗时</th>
            <th>耗时最长调用</th>
            <th>耗时最长调用发生时间</th>
            <th>耗时最长调用运行时间</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($all_status as $v):?>
            <tr>
                <td><?php echo $v['status']?></td>
                <td><?php echo $v['count']?></td>
                <td><?php echo $all['count']?(round($v['count']/$all['count'],6)*100).'%':'0%'?></td>
                <td><?php echo $v['count']?round($v['all_run_time']/$v['count'],6):''?></td>
                <td><?php echo $v['longest']['method']?></td>
                <td><?php echo $v['longest']['request_time']?></td>
                <td><?php echo $v['longest']['run_time']?></td>
            </tr>
        <?php endforeach;?>
        </tbody>
    </table>
    <table class="table table-bordered">
        <thead>
        <tr>
            <th>时间</th>
            <th>调用总数</th>
            <th>占比</th>
            <th>平均耗时</th>
            <th>耗时最长调用</th>
            <th>耗时最长调用发生时间</th>
            <th>耗时最长调用运行时间</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($all_time as $k=>$v):?>
            <tr>
                <td><?php echo $k?></td>
                <td><?php echo $v['count']?></td>
                <td><?php echo $all['count']?(round($v['count']/$all['count'],6)*100).'%':'0%'?></td>
                <td><?php echo $v['count']?round($v['all_run_time']/$v['count'],6):0?></td>
                <td><?php echo $v['longest']['method']?></td>
                <td><?php echo $v['longest']['request_time']?></td>
                <td><?php echo $v['longest']['run_time']?></td>
            </tr>
        <?php endforeach;?>
        </tbody>
    </table>
</div>

</body>
</html>