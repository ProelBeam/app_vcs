<?php
return [
	// 项目标识(🔅必填)
	'project_id'         => '',
	// 通讯域名(🔅必填)： 注册时会生成一串独一且固定的通迅文件，用于和平台交互
	'client_connect_url' => 'http://dev.a1.tzkj.com',
	// 服务地址(🔅必填)
	'server_url'         => 'https://www.baidu.com',
	// 客户端ID(🉑非必填)
	'client_id'          => '',
	// 应用ID (🔅必填)
	'app_id'             => '',
	// 执行时生成的临时文件进行存储的目录(非必填)
	'temp_file_path'     => '',
	// 备份目录 (🉑非必填)
	'backup_path'        => '',
	// 安装sdk的服务端目录 (🔅必填)
	'root_path'          => dirname(__DIR__),
	// 项目目录 需要更新的代码目录 (🔅必填)
	'project_path'       => dirname(__DIR__),
	// 数据库配置(🉑非必填)
	'database'           => [
		'driver'   => 'mysql',
		'host'     => '127.0.0.1',
		'port'     => 3306,
		'database' => 'lhr_app',
		'username' => 'root',
		'password' => '',
	],
];