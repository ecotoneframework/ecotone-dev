# Testing

This quick-start example shows, how you can test your Ecotone applications.  
It provides examples of testing `Aggregates`, `Sagas`, `Projections`.  
Besides that you may combine those for writing acceptance tests that span over several building blocks like `Aggregates` and `Projections` together.  

# Running

By running `run example.php` we are running production like code, that will store everything in database.  
By running `vendor/bin/phpunit` we are running test for our code.  
If you look closely we are able to test all Ecotone's production code with ease, and simply switch to production storages when we are ready.  
You may take a look into database to see, how data is stored.
