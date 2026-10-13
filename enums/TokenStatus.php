<?php

namespace App\Enums;

enum TokenStatus: int
{
    case Alive = 1;
    case Expired = 0;
    case NotExist = -1;

    /**
     * Undocumented function
     *
     * @return string
     */
    public function stat(): string
    {
        return match ($this)
        {
            TokenStatus::Alive => "auhorized",
            TokenStatus::Expired => "not authorized",
            TokenStatus::NotExist => "not exist"
        };
    }
}
