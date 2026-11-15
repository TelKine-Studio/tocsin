@extends('errors.layout')

@section('code', '405')
@section('icon', 'alert')
@section('title', __('This address does not open in a browser'))
@section('message', __('It is used by apps and scripts, for example to publish with curl. Go back to the home page to find your topics.'))
