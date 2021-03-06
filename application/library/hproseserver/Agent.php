<?php

/**
 * 获取/处理用户信息
 * Created by PhpStorm.
 * User: marin
 * Date: 2017/5/27
 * Time: 13:36
 */
class hProseServer_Agent extends Mysql_MysqlUsePool
{

    public function getAgentId()
    {
        $data = hProseServer_Factory::NewDB() ->query('select * from `user` ')->fetchAll(PDO::FETCH_ASSOC);
        return writeToJson(1000,'',$data);
    }
    public function updateUser($data,$where)
    {
        $data = $this ->update('`user`',$data,$where);
        return writeToJson(1001,'',$data);
    }

    public function userList($sql,$page='',$pageSize='',$order='')
    {
        $data = $this ->select($sql,$page,$pageSize,$order);
        return writeToJson(1002,'',$data);
    }
}
