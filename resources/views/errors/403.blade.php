@extends('errors.layout')

@section('title', '403')
@section('code', '403')
@section('message', $exception->getMessage() ?: 'You don\'t have permission to access this page.')
