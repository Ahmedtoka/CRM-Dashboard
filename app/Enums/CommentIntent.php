<?php

namespace App\Enums;

enum CommentIntent: string
{
    case Buy = 'buy';
    case Question = 'question';
    case Complaint = 'complaint';
    case Spam = 'spam';
    case Other = 'other';
}
