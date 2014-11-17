# basic UNION support for CakePHP

`Model->find()` replacement for UNIONed queries

Sometimes you can not simply use an `OR` due to database performance reasons
*(whole table scanning, ALL type in explain, etc.)*.

So you might need to break the query into multiple `UNION` based selects.

This is not something CakePHP supports.

So this Plugin attempts to let you use the rest of your Cake find
functionality and only have to use the `UNION` when you need to.

## Install

    git submodule add https://github.com/zeroasterisk/CakePHP-Unionizable.git app/Plugin/Unionize
    echo "CakePlugin::load('Unionize', array('bootstrap' => false, 'routes' => false));" >> app/Config/bootstrap.php

## Usage: set conditions, find

```
$Model->unionizeSetConditions(['MyModel.myfield' => 'foobar']);
$Model->unionizeSetConditions(['SomeOtherModel.someField' => 'foobar']);
$results = $Model->unionizeFind('all', ['limit' => 2, 'order' => false]);
```

supported find types:

* 'all'
* 'count' = 'count-real'
* 'count-real' = excludes duplicate records (so A + A + B = 2)
* 'count-fast' = included duplicate records (so A + A + B = 3)

## TODO:

* implement
  [paginate](http://book.cakephp.org/2.0/en/core-libraries/components/pagination.html)
  behavior hooks (if we have conditions set)
* translate all fields to aliases and back (which gives us full sort support)
* any way to make the find "type" work?
