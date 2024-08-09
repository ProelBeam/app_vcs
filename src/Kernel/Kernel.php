<?php

namespace Lcli\AppVcs\Kernel;

use Lcli\AppVcs\Helpers;
use Lcli\AppVcs\AppVcsException;
use ZipArchive;
use function AlibabaCloud\Client\value;

class Kernel {
	
	
	/**
	 * 检查更新
	 * @param $clientId
	 * @param $appId
	 * @param $version
	 * @return array|mixed
	 */
	public static function check($appId, $version = null)
	{
		return \Lcli\AppVcs\Kernel\Request::instance()->check([ 'appId' => $appId, 'version' => $version ]);
	}
	
	/**
	 * 下载更新包
	 * @param $clientId
	 * @param $appId
	 * @param $version
	 * @return array|mixed
	 * @throws \Lcli\AppVcs\AppVcsException
	 */
	public static function upgrade($appId, $version = null)
	{
		
		// 开始事务
		$transction = new Transaction();
		
		$transction->start(['appId' => $appId, 'version' => $version]);
		
		$result = \Lcli\AppVcs\Kernel\Request::instance()->upgrade([ 'appId' => $appId, 'version' => $version ]);
		if (!$result){
			throw new  AppVcsException('获取版本信息失败');
		}
		$versionInfo = isset($result['versionInfo'])?$result['versionInfo']:[];
		// 获取发布操作类型:0=按需发布,1=全量发布
		$publishAction = isset($versionInfo['publish_action'])?$versionInfo['publish_action']:0;
		$upgradeVersion =  $versionInfo['version'];
		// 保存更新数据
		Helpers::setUpgradeData($result);
		
		try {
			// 操作更新
			$files = isset($result['files']) ?$result[ 'files' ]: [];
			$url = $result[ 'url' ];
			$fileName = isset($result[ 'fileName' ])?$result[ 'fileName' ]:'app-vcs-upgrade.zip';
			$tempFilePath = Helpers::getTempFilePath($upgradeVersion);
			if (!$tempFilePath) {
				throw new  AppVcsException('请配置根目录');
			}
			is_dir($tempFilePath) or mkdir($tempFilePath, 0755, true);
			
			$rootPath = Helpers::getRootPath();
			if (!$rootPath) {
				throw new  AppVcsException('请配置根目录');
			}
		
			$checkDir = Helpers::checkPath($rootPath);
			
			if (!$checkDir){
				return false;
			}
			is_dir($rootPath) or mkdir($rootPath, 0755, true);
			// 项目目录
			$projectPath  = Helpers::getProjectPath();
			if (!$projectPath){
				$projectPath = $rootPath;
			}
			
			$zipFile = $tempFilePath . '/' . $fileName;
			
			// 下载远程文件并保存到本地
			$filePutRsult = @file_put_contents($zipFile, file_get_contents($url));
			
			$destinationDir = $tempFilePath;
			if ($filePutRsult){
				$zip = new ZipArchive();
				if ($zip->open($zipFile) === TRUE) {
					$zip->extractTo($destinationDir);
				}
				$zip->close();
			}
			
			// 1.备份文件
			// 如果是全量发布， 那么备份全部文件
			if (intval($publishAction) === 1){
				// 全量发布需要删除原先代码, 然后将新的文件下载到目录下
				$projectFiles = FileSystem::getAllFiles($projectPath, false);
				$_files = [];
				
				foreach ($projectFiles as $fileFullPath) {
					$path = str_replace($projectPath , '', $fileFullPath);
					$_files[] = [
						'path' => $path,
						'fullPath' => $fileFullPath
					];
				}
				$backupStatus = Backup::file($_files, $result);
				
			}else{
				$backupStatus = Backup::file($files, $result);
			}
			
			if (!$backupStatus){
				throw new  AppVcsException('升级失败:备份文件失败');
			}
			// 2.备份数据库
			$issetTables = isset($versionInfo['tables_files']);
			$backup = $issetTables?$versionInfo['tables_files']:[];
			
			$backupStatus = Backup::database($backup, $upgradeVersion);
			if (!$backupStatus){
				throw new  AppVcsException('升级失败:备份sql失败');
			}
			
			
			
			
			if (intval($publishAction) === 1){
				// 全量发布需要删除原先代码, 然后将新的文件下载到目录下
				FileSystem::clearDir($projectPath);
			}
			
			// 3.开始执行升级
			foreach ( $files as $file ) {
				$state = $file[ 'state' ];
				$path = $file[ 'path' ];
				$type = $file[ 'type' ];
				if (!$path) {
					continue;
				}
				
				// 获取更新文件
				$upgradeFilePath = $destinationDir . '/' . $path;
				$localFilePath = $projectPath . '/' . $path;
				
				// 安全文件只运行不下载
				$safeFileOrDirs = [Helpers::getDatabaseSqlPath($upgradeVersion)];
				if (isset($versionInfo['safe_files']) && is_array($versionInfo['safe_files']) && $versionInfo['safe_files']){
					$safeFileOrDirs = array_merge($safeFileOrDirs, $versionInfo['safe_files']);
				}
				// 过滤文件
				$filterFiles = isset($versionInfo['filter_files'])?$versionInfo['filter_files']:[];
				if (!$filterFiles){
					$filterFiles = [];
				}
				
				// 存在指定过滤文件， 那么直接过滤
				if (in_array($path, $filterFiles)   ){
					continue;
				}
			 
				// 如果包含， 也过滤
				foreach ($filterFiles as $filterFileItem){
					$isContains =  strpos($path, $filterFileItem)!==false;
					if (!$isContains){
						break;
					}
				}
				 
				if (!in_array($path, $safeFileOrDirs)){
					
					
					switch ($type) {
						case 'file':
							Migrate::file($state, $localFilePath, $upgradeFilePath);
							
							break;
						case 'sql': // 数据库迁移
							
							Migrate::database($versionInfo['version']);
							break;
						case 'config': // 配置更新,
						default: // 业务数据更新
							
							Migrate::file($state, $localFilePath, $upgradeFilePath);
							break;
					}
				}
				
			}
			
			// 测试升级失败
			// $d = 1/0;
			
			// 如果有脚本指令, 运行脚本
			$scripts = isset($versionInfo['script'])?$versionInfo['script']:[];
			if ($scripts && is_array($scripts)){
				foreach ($scripts as $cmd){
					shell_exec($cmd);
				}
			}
			 
			// 提交事务
			$transction->success($result);
			return true;
		} catch (\Error $e){
			self::throwError($result, $transction, $e);
		} catch (\Exception $e){
			
			
			// 回滚程序
			self::throwError($result, $transction, $e);
		}
	}
	
	public static function throwError($result, $transction, $e)
	{
		$errorData = [
			'upgrade' => $result,
		];
		$transction->rollback($errorData, ['message' => $e->getMessage(), 'trace' => $e->getTrace(),'file' => $e->getFile(), 'line' => $e->getLine() ]);
		throw new AppVcsException($e->getMessage());
	}
	
	public static function rollback()
	{
		  Backup::rollback();
	}
	
	
	
	/**
	 * 获取系统版本
	 * @return false|string
	 */
	public static function version()
	{
		return Helpers::getVersion();
	}
	
	
	
	
}