# Census Geocoder External Module
The Census Geocoder's main function is to map REDCap address or location data onto user-selectable Census geocode attributes. These include the FIPS codes for block, tract, county and state; as well as other geographic attribute values.

## Project Configuration

> **Note: All REDCap fields in the following settings must be located on the same form.**  

### Address and location fields

These REDCap fields will be used in the Census geocode API calls to fetch geocoding data. If an address match fails or an address is not available, a match by location (latitude and longitude) can be attempted. 

- **Field Containing the Full Address**: The field containing the address for which you wish to receive US Census data.  

- **Fields Containing the Latitude/Longitude (if address not found)**: These fields are most useful when populated by the [Address Autocomplete External Module](https://github.com/vanderbilt-redcap/address-autocomplete).  

### User Interface Option  

- **Add button(s) to trigger API calls** By default, geocoding will occur in real time as data (address, latitude, longitude) are entered or edited. You may elect to to trigger geocoding manually using a button instead. If you specify both address and location (latitude/longitude) fields, separate buttons will be added for each geocoding method.

### Census Benchmarks and Geocode Field Mapping  

You may select any number of Census Benchmark-Vintage combinations from which to geocode your data. 
For each benchmark-vintage combination, you specify:

- **Benchmark - Vintage**: The Benchmark and Vintage from which you wish to receive data. Only currently supported combinations are listed, these are checked upon opening the module configuration menu. Note that options containing "Current" are subject to flux and are typically not recommended to be used.  

    - "Benchmark" refers to the time period when the address range was captured in TIGER, "Vintage" is the date when the geography information was captured. For more information see [the official FAQ](https://www2.census.gov/geo/pdfs/maps-data/data/FAQ_for_Census_Bureau_Public_Geocoder.pdf).  

- **Field Mappings**  A list of ```TigerWeb data keys``` and ```REDCap field names```, as follows:

    - **Data Key from TigerWeb Available Fields**: The attribute which you wish to receive from the US Census API.
        - Associated fields will receive the value associated with the smallest geographic region (ordered by land area) in which the specified attribute appears; please be aware that not every key listed here is guaranteed to appear for every address in every **Benchmark - Vintage**.  

        - See [TigerWeb](https://tigerweb.geo.census.gov/tigerwebmain/TIGERweb_attribute_glossary.html?) documentation for descriptions of these attributes.  

    - **Field to Populate with Corresponding Data Key**: The REDCap field to populate with the specified **Data Key from TigerWeb Available Fields**.

### Optional Geocoding Summary Fields  

Implementing these optional fields will allow you to track geocoding results, and will help resolve address match failures.

- **Optional text field to populate with a geocoding match result.** A REDCap field to store a result code for the match result. 
Possible values are ```matched```,  ```not matched``` and ```multiple matches```. A result of ```multiple matches``` means that the provided
address was matched to more than one distinct geocoded address.  

- **Optional notes/paragraph field to populate with a geocoding summary report.** A REDCap field to store a brief report summarizing the match.  

The summary report will include:  

   - Date and time of the API call,
   - The match result (matched/not matched/multiple matches),
   - A list of all distinct matched addresses, within each Census benchmark-vintage and across all benchmark-vintages.  
   - For each benchmark-vintage combination:
     - The Benchmark and Vintage
     - The geocoded address
     - The number of geocode fields that were populated
  
## Screen shot  

The following screen shot includes REDCap fields populated with requested geographic attribute values, as well as the match result and the geocode summary report.

![Screen shot of Census Geocoder UI](screenshot.png)