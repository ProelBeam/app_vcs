<?php

namespace Lcli\AppVcs\Kernel;

use Lcli\AppVcs\Helpers;
use splitbrain\phpcli\CLI;

class Backup {
	
	
	public static function fixPaht($path)
	{
		$path = str_replace('"', '', $path);
		$path = str_replace('\\', '/', $path);
		$list = explode('/', $path);
		return $path;
	}
	
	/**
	 * 备份文件
	 * @param array $upgradeFiles 需要备份的文件
	 * @return bool
	 */
	public static function file($upgradeFiles, $upgradeData)
	{
		$rootPath   = Helpers::getRootPath();
		$backupPath = Helpers::getBackupPath();
		$version    = Helpers::getVersion();
		
		foreach ($upgradeFiles as $file) {
			$path = self::fixPaht($file['path']);
			if (!$path) {
				continue;
			}
			$filePath     = $backupPath . '/' . $path;
			$tempFilePath = explode('/', $filePath);
			unset($tempFilePath[count($tempFilePath) - 1]);
			$fileDir = implode('/', $tempFilePath);
			is_dir($fileDir) or mkdir($fileDir, 0775, true);
			$localFilePath = $rootPath . '/' . $path;
			if (file_exists($localFilePath)) {
				FileSystem::writeByPath($filePath, $localFilePath);
			}
		}
		
		return true;
	}
	
	/**
	 * 备份数据库
	 * @param array $tables 需要备份的表名
	 * @return bool
	 */
	public static function database($tables, $upgradeVersion)
	{
		
		$tables      = array_unique($tables);
		$sqlFilePath = Helpers::generatedDatabaseSqlFilename($upgradeVersion);
		$sqlTables   = Db::getOperatorTableRecords($sqlFilePath);
		if ($sqlTables){
			$tables      = array_merge($tables, $sqlTables);
			// 设置操作表
			Helpers::setUpgradeData( ['versionInfo'=> ['tables_files' => $tables]]);
		}
	 
		
		$database    = Helpers::getDbConfig();
		$host        = $database['host'];
		$port        = $database['port'];
		$db          = $database['database'];
		$username    = $database['username'];
		$password    = $database['password'];
		$version     = Helpers::getVersion();
		$backupFile  = Helpers::getBackupDbName();
		
		// 构建mysqldump命令
		$command =
			"mysqldump  --socket=/tmp/mysql.sock  -u'{$username}' -p'{$password}' {$db} " . implode(' ', $tables) . ">'{$backupFile}'";
		
		// 执行命令
		exec($command, $output, $return_var);
		// 检查命令执行状态
		if (in_array(intval($return_var), [
				0,
				6
			]) && file_exists($backupFile)) {
			return true;
		}
		
		
		return false;
	}
	
	/**
	 * 回滚代码
	 * @return void
	 */
	public static function rollback($data = null)
	{
		// 获取备份文件
		static::rollbackFile($data);
		static::rollbackDb($data);
	}
	
	/**
	 * 备份文件
	 * @param array $upgradeFiles 需要备份的文件
	 * @return bool
	 */
	protected static function rollbackFile($data = [])
	{
		$upgradeData  = Helpers::getUpgradeData();
		$upgradeFiles = isset($upgradeData['files']) ? $upgradeData['files'] : [];
		
		$rootPath   = Helpers::getRootPath();
		$backupPath = Helpers::getBackupPath();
		
		foreach ($upgradeFiles as $file) {
			$path = $file['path'];
			if (!$path) {
				continue;
			}
			
			
			$backupFilePath = $backupPath . '/' . $path;
			$localFilePath  = $rootPath . '/' . $path;
			if (file_exists($backupFilePath)){
				FileSystem::write($localFilePath, file_get_contents($backupFilePath));
			}else{
				// 新增的文件则删除
				if ($file['state'] === 'A') { // A=新增
					FileSystem::delete($localFilePath);
				}
			}
			
			
		}
		return true;
	}
	
	/**
	 * 回滚数据库
	 * @param array $tables 需要备份的表名
	 * @return bool
	 */
	protected static function rollbackDb($data = [])
	{
		
		$upgradeData  = isset($data['upgrade']) ? $data['upgrade'] : [];
		$upgradeVersion = $upgradeData['version'];
		$rollbackDbPath = Helpers::getRollbackSqlPath($upgradeVersion);
		$rollbackFile = $rollbackDbPath . '/v' . $upgradeVersion . '.sql';
		$database     = Db::instance();
		$backupDbFile = Helpers::getBackupDbName();
		
		
		// 组合回滚文件和备份文件，执行顺序：创建表>备份表>回滚文件
		$backupDbList = [
			$backupDbFile,
			$rollbackFile
		];
		
		foreach ($backupDbList as $sqlFile) {
			if (file_exists($sqlFile)) {
				$sqlScript = file_get_contents($sqlFile);
				
				// 使用正则表达式分割SQL脚本成单个语句
				$delimiter     = ';';          // SQL语句结束符
				$pattern       = "/;(\r?\n)/"; // 正则表达式匹配语句结束符后跟换行符
				$sqlStatements = preg_split($pattern, $sqlScript);
				$cli = new \Lcli\AppVcs\Cli\Cli();
				// 执行每个SQL语句
				foreach ($sqlStatements as $stmt) {
					$stmt = trim($stmt); // 去除首尾空白字符
					if (!empty($stmt)) { // 检查SQL语句是否为空
						if ($database->query($stmt) === FALSE) {
							$cli->error('Error executing query: ' . $database->error() . ', sql:' . $stmt”);
							break; // 如果有错误，停止执行
						}
					}
				}
			}
		}
		// 创建了什么表， 创建了就删除
		$sqlFilePath = Helpers::generatedDatabaseSqlFilename($upgradeVersion);
		$sqlTables   = Db::getCreateTableRecords($sqlFilePath);
		if ($sqlTables){
			foreach ($sqlTables as $tableName){
				$database->query("DROP TABLE {$tableName};");
			}
		}
		return false;
	}
	
}