---
title: "Doctrine 2.2 released"
authorName: Benjamin Eberlei
authorEmail:
categories: [release]
permalink: /2012/01/29/doctrine-2-2-final.html
---
We released Doctrine 2.2 today.

A top list of the new features includes:

-   Filtering entities and associations based on rules that can be
    parameterized, enabled or disabled, developed by asm89
-   Support for complex SQL types such as Geometries, IPs, develped by
    jsor.
-   Bit Comparisions in DQL, developed by Fabio.
-   Annotation Refactorings by Fabio and johannes.
-   DQL Refactoring, ORDER BY and GROUP BY supporting result variables
    of SELECT expressions.
-   Alias for entities in DQL results.
-   Result Cache refactoring
-   Flush for single entities
-   Master/Slave Connection in DBAL

See the changelogs of all three projects Common, DBAL, ORM:

-   [ORM](https://www.doctrine-project.org/jira/browse/DDC/fixforversion/10157)
-   [DBAL](https://www.doctrine-project.org/jira/browse/DBAL/fixforversion/10142)
-   [Common](https://www.doctrine-project.org/jira/browse/DCOM/fixforversion/10152)

See the
[UPGRADE\_2\_2](https://github.com/doctrine/orm/blob/master/UPGRADE.md#upgrade-to-22)
file to see backwards incompatible changes.

You can install the release through
[Github](https://github.com/doctrine/orm) ,
[PEAR](http://pear.doctrine-project.org) or through
[Composer](https://packagist.org):

> {
> :   "require": { "doctrine/orm": "2.2.0" }
>
> }
