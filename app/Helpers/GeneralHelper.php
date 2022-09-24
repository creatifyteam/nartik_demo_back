<?php


function successCode(): array
{
    return [200, 201, 202];
}

function responseStatus($code): string
{
    return in_array($code, successCode()) ? 'success' : 'error';
}

?>