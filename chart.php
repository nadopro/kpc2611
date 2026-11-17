 
    <script type="text/javascript" src="https://www.gstatic.com/charts/loader.js"></script>
    <script type="text/javascript">
      google.charts.load('current', {'packages':['corechart']});
      google.charts.setOnLoadCallback(drawChart);

      function drawChart() {
        var data = google.visualization.arrayToDataTable([
          ['Year', '컴퓨터수', '클릭수', '사용자수'],
          ['2004',  1000,      400, 77],
          ['2005',  1170,      460, 88],
          ['2006',  660,       1120, 99],
          ['2007',  1030,      540, 250]
        ]);

        var options = {
          title: '접속 로그',
          curveType: 'function',
          legend: { position: 'bottom' }
        };

        var chart = new google.visualization.LineChart(document.getElementById('curve_chart'));

        chart.draw(data, options);

        var chart2 = new google.visualization.LineChart(document.getElementById('kpc_chart'));

        chart2.draw(data, options);
      }
    </script>
 
    <div id="curve_chart" style="width: 900px; height: 500px"></div>

    <div id="kpc_chart" style="width: 500px; height: 500px"></div>

