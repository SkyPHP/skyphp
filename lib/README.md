# /lib

Your project should have its own codebase with a `/lib` folder.  Put all your libraries
and classes and javascript libraries here.

For example, you will probably have a folder called `/lib/MyProject/Models/` and various
other classes organized into namespaces like `/lib/MyProject/Fu/Bar.php`.

Make sure to use PSR-0 naming conventions
[<https://github.com/php-fig/fig-standards/blob/master/accepted/PSR-0.md>].

Also put your git submodules and/or third-party libraries here.  When cloning, make sure
to use the `git clone --recursive` flag to automatically pull all submodules.
