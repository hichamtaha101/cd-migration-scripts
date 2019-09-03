const https = require('https')

https.get('https://marcelo-showroom.achilles.ninja/wp-admin/admin-ajax.php?action=showroom_get_data&endpoint=models&imageSize=xs&language=en', (resp) => {
    let data = '';

    // A chunk of data has been recieved.
    resp.on('data', (chunk) => {
        data += chunk;
    });

    // The whole response has been received. Print out the result.
    resp.on('end', () => {
        let newData = JSON.parse(data);
        let filteredData = newData.data.filter( d => d.image === 'https://marcelo-showroom.achilles.ninja/wp-content/plugins/convertus-showroom/include/images/no-car-image-xs.png' ).map( d => {
            return {
                make: d.make, 
                model: d.model, 
                year: d.year
            }
        });

        console.log( JSON.stringify(filteredData) );
        console.log( 'Total: ' + filteredData.length );
    });
}).on("error", (err) => {
  console.log("Error: " + err.message);
});