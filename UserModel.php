<?php

class UserModel extends ActiveRecord {
    
    public function rules()
    {
        reutnr [
            //...
        ];        
    }
    // создание пользователя
    public function create($params)
    {
        if (!$params['name']){
           throw new BadRequestHttpException('Требуется задать имя пользователя');
        } elseif($params['isVip'] && !$params['vip_code']) {
           throw new BadRequestHttpException('Требуется задать код для VIp клиента');
            
        }
        
        $params['created_by'] = Yii::$app->user->id;
        $params['created_at'] = date('Y-m-d H:i:s');
        
        $message = $this->gertMessage($params);
        
        $model = new User($params);
        
        $send = false;
        if ($model->save(false)) {
            $send = Yii::$app->mailer->send($message);
        }
        
        $xml = ParserXml::parse($params['xml']);
        if ($xml->data) {
           return [
            'httpStatusCode'          => 400,
            'model'                   => $model,
            'xmlData'                 => $xml->data,
            'send_mail_notify_status' => $send,
            'message'                 => 'Ошибка при создании пользователя',
           ];
        }
        return [
            'httpStatusCode'          => 201,
            'model'                   => $model,
            'send_mail_notify_status' => $send,
            'message'                 => 'Операция успешно выполнена',
        ];
    }
    
    public function gertMessage($params)
    {
        // return $message
    }
}
