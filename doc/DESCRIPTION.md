Nginx Mapper is an admin-only dashboard that turns your server's nginx access
logs into a picture of where your traffic comes from.

- A **world map** plots the geographic origin of client IPs across every site's
  nginx access log, with one marker per location sized by request volume.
- A **user-agent bar chart** lists the most common user-agents and how often
  each one appears.
- A **top-countries** table summarises request volume by country.

IP-to-location lookups use a local **DB-IP City Lite** database (free,
CC-BY-4.0, no licence key). A **Refresh** button downloads the latest copy on
demand, and a monthly cron keeps it current.

The dashboard is restricted to the **admins** group and is not meant to be
public, since it exposes traffic data for the whole server. Private and
reserved IP ranges are never mapped.
