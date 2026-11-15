@extends('errors.layout')

@section('code', (string) $exception->getStatusCode())
@section('icon', 'alert')
@section('title', __('This request cannot be completed'))
@section('message', __('Check the address, then try again.'))

