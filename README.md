# AWS SES Feedback Reporter

**The AWS SES Feedback Reporter allows to collect bounces and complaint from Amazon's Simple
Email Service via the HTTP interface of SNS.**

Just point the SNS topics for SES's bounces and complaint to the root of this application
and it will store the notifications to MongoDB. You can then view and filter the notifications
to extract them for a specific mailing.

Installation:

* Do a composer install
* Copy /src/parameters.yml.dist to /src/parameters.yml and fill in your parameters
* Setup a virtual host for this application

Requirements:
* PHP
* Apache Webserver
* MongoDB

This is a Silex based application.
