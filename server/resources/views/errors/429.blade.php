@extends('errors.layout')

@section('code', '429')
@section('icon', 'clock')
@section('title', __('Too many requests'))
@section('message', __('Slow down a little: wait a moment, then try again.'))

