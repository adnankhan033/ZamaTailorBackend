#!/bin/bash
echo "=== Customer Profiles API Diagnostic ==="
echo ""
echo "1. Checking module status..."
drush pm:list | grep custom_rest_api
echo ""
echo "2. Checking REST module..."
drush pm:list | grep rest
echo ""
echo "3. Checking REST resource config..."
drush config:get rest.resource.customer_profile_list_resource status 2>/dev/null || echo "Config not found!"
echo ""
echo "4. Checking permissions..."
drush user:role:permissions authenticated | grep customer_profile_list_resource || echo "Permission not found!"
echo ""
echo "5. Clearing cache..."
drush cr
echo ""
echo "=== Diagnostic Complete ==="
