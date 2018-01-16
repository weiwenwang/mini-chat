<?php
include_once("conf.php");
$ws = new swoole_websocket_server("0.0.0.0", 9502);
const ALL_FD = 'all_fd';
const TOTAL_PERSON = 'total_person';
const MSG_TYPE_INFO = 1;
const MSG_TYPE_CHAT = 2;
const MSG_TYPE_NOTICE = 3;
const MSG_TYPE_COUNT = 4;
const MSG_TYPE_ALL_PERSON = 5;

$redis = getRedis();
$redis->delete('all_fd'); // 清除聊天室内的所有人
$all_person = $conf['all_person'];
foreach ($all_person as $key => $value) {
	$redis->hmset('uid:' . $value['uid'], $value);
	$redis->sAdd('all_person_uid', $value['uid']);
}

$ws->set(array(
	'task_worker_num' => 4,
	// 'heartbeat_check_interval' => 60,
));

//处理异步任务的结果
$ws->on('finish', function ($serv, $task_id, $data) {
    echo "AsyncTask[$task_id] Finish: $data".PHP_EOL;
});

//处理异步任务
$ws->on('task', function ($serv, $task_id, $from_id, $data) {
    echo "New AsyncTask[id=$task_id]".PHP_EOL;
    //返回任务执行的结果
    $serv->finish("$data -> OK");
});

//监听WebSocket连接打开事件
$ws->on('open', function ($ws, $request) {
	$redis = getRedis();
	$your_name_id = $redis->spop('all_person_uid');
	$redis->set("fd:". $request->fd, $your_name_id);
	$your_name = $redis->hget('uid:' . $your_name_id, 'name');
	$redis->sAdd("all_fd", $request->fd);
	$redis->incr(TOTAL_PERSON, 1);
	$info_msg = getPersonInfoMsg($your_name_id);
	$hello_msg = getHelloMsg();
	$count_msg = getCountMsg();
	$all_person = getAllPerson();
	// 发给当前登录用户的
	$msg_json = json_encode([$info_msg, $count_msg, $hello_msg, $all_person]);
	$msg_notice = getNoticeMsg($your_name_id, '', '上线了');
	$all_fd = $redis->smembers('all_fd');
	foreach ($all_fd as $key => $value) { // 告诉其他人$fd下线了
		if ($request->fd == $value) $msg_notice = str_replace('上线了', '成功登录', $msg_notice);
		$ws->push($value, json_encode([$msg_notice])); // 列表去掉某个人的信息
	}


  $ws->push($request->fd, $msg_json);
});

//监听WebSocket消息事件
$ws->on('message', function ($ws, $frame) {
	$redis = getRedis();
	$all_fd = $redis->smembers('all_fd');
	$uid = $redis->get("fd:". $frame->fd);
	$chat_msg = getChatMsg($uid, $frame->data);
	foreach ($all_fd as $key => $value) {
		if ($frame->fd != $value) {
			$ws->push($value, json_encode([$chat_msg, ]));
		}
	}
});

//监听WebSocket连接关闭事件
$ws->on('close', function ($ws, $fd) {
	$redis = getRedis();
	$redis->srem('all_fd', $fd);
	$all_fd = $redis->smembers('all_fd');
	$name_id = $redis->get("fd:". $fd);
	$redis->incr(TOTAL_PERSON, -1);
	// 把名字环境集合中
	$redis->sAdd('all_person_uid', $name_id);
	$msg_notice = getNoticeMsg($name_id, '', '下线了');
	$msg3 = getCountMsg();
	foreach ($all_fd as $key => $value) { // 告诉其他人$fd下线了 告诉其他人, 现在还有多少人在线
			$ws->push($value, json_encode([$msg_notice, $msg3]));
	}
});

$ws->start();

function getRedis() {
	$redis = new Redis();
	$redis->connect('192.168.1.9', 6379);
	return $redis;
}
function getPersonCount() {
	$redis = getRedis();
	$count = $redis->scard(ALL_FD);
	return $count;
}

function getCountMsg() {
	$redis = getRedis();
	$count = $redis->get(TOTAL_PERSON);
	$msg = MSG_TYPE_COUNT . '_' . $count;
	return $msg;
}

function getPersonInfoMsg($uid) {
	$redis = getRedis();
	$info = $redis->hmget('uid:' . $uid, ['name', 'img']);

	$msg = MSG_TYPE_INFO . '_' . json_encode($info);
	return $msg;
}

function getNoticeMsg($uid, $name, $append = null) {
	if (!$name) {
		$redis = getRedis();
		$name = $redis->hget('uid:' . $uid, 'name' );
	}
	$msg = MSG_TYPE_NOTICE . "_{$name} ";
	if ($append !== null) {
		$msg .= $append;
	}
	return $msg;
}

function getChatMsg($uid, $data) {
	$redis = getRedis();
	$person_info= $redis->hmget("uid:" . $uid, ['uid', 'name', 'img']);
	$msg = MSG_TYPE_CHAT . '_' .json_encode([
		'uid' => $person_info['uid'],
		'name' => $person_info['name'],
		'img' => $person_info['img'],
		'content' => $data,
	]);
	return $msg;
}

function getHelloMsg() {
	$msg = MSG_TYPE_CHAT . '_' . json_encode(
		[
			'uid' => 1,
			'name' => "mini-chat",
			'img' => 'https://i.loli.net/2018/04/18/5ad70a7c10676.png',
			'content' => "welcome, mini-chat",
		]
	);
	return $msg;
}

function getAllPerson() {
	$redis = getRedis();
	$all_fd = $redis->smembers('all_fd');
	$all_person = [];
	foreach ($all_fd as $key => $value) {
		$person_info = $redis->hgetall('uid:' . $redis->get('fd:' . $value));
		$person_info['fd'] = $value;
		$all_person[] = $person_info;
	}
	$msg = MSG_TYPE_ALL_PERSON . '_' . json_encode($all_person);
	return $msg;
}
