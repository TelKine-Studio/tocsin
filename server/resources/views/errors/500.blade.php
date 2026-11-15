@extends('errors.layout')

@section('code', '500')
@section('icon', 'alert')
@section('title', __('Something went wrong'))
@section('message', __('An unexpected error occurred on the server. Try again in a moment; if it persists, contact the administrator.'))

