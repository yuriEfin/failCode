<?php

class UserModel extends ActiveRecord {
    // создание пользователя
    public function create($params)
    {
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
