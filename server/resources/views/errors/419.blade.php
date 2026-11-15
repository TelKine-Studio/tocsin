@extends('errors.layout')

@section('code', '419')
@section('icon', 'refresh')
@section('title', __('Page expired'))
@section('message', __('The page stayed open too long. Reload it and try again.'))
@section('action')<x-ui.button type="button" icon="refresh" onclick="location.reload()">{{ __('Reload') }}</x-ui.button>@endsection
