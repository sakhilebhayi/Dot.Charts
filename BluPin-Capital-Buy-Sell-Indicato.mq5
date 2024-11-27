//+------------------------------------------------------------------+
//|                                                      Custom Indicator Template |
//+------------------------------------------------------------------+
#property indicator_chart_window
#property indicator_buffers 6
#property indicator_color1 Green
#property indicator_color2 Red

input int SamplingPeriod = 50;       // Sampling Period
input double RangeMultiplier = 3.0;  // Range Multiplier

double src[], filt[], smrng[], upward[], downward[];
double hband[], lband[], longCondition[], shortCondition[];

//+------------------------------------------------------------------+
//| Custom indicator initialization function                         |
//+------------------------------------------------------------------+
int OnInit()
{
   // Buffers setup
   SetIndexBuffer(0, filt);
   SetIndexBuffer(1, smrng);
   SetIndexBuffer(2, hband);
   SetIndexBuffer(3, lband);
   SetIndexBuffer(4, longCondition);
   SetIndexBuffer(5, shortCondition);
   
   PlotIndexSetInteger(0, PLOT_ARROW, 233);   // Buy arrow
   PlotIndexSetInteger(1, PLOT_ARROW, 234);  // Sell arrow
   
   return(INIT_SUCCEEDED);
}

//+------------------------------------------------------------------+
//| Custom indicator iteration function                              |
//+------------------------------------------------------------------+
int OnCalculate(const int rates_total, const int prev_calculated,
                const datetime &time[], const double &open[],
                const double &high[], const double &low[], const double &close[],
                const long &tick_volume[], const long &volume[], const int &spread[])
{
   int begin = MathMax(SamplingPeriod, prev_calculated);
   
   for(int i = begin; i < rates_total; i++)
   {
      // Calculate smooth range
      double avrng = iEMA(MathAbs(close[i] - close[i-1]), SamplingPeriod, i);
      double wper = (SamplingPeriod * 2) - 1;
      smrng[i] = iEMA(avrng, wper, i) * RangeMultiplier;

      // Range Filter logic
      if(i > 0)
      {
         if(close[i] > filt[i-1])
            filt[i] = MathMax(close[i] - smrng[i], filt[i-1]);
         else
            filt[i] = MathMin(close[i] + smrng[i], filt[i-1]);
      }
      else
      {
         filt[i] = close[i];
      }

      // Filter direction
      if(i > 0)
      {
         upward[i] = filt[i] > filt[i-1] ? upward[i-1] + 1 : 0;
         downward[i] = filt[i] < filt[i-1] ? downward[i-1] + 1 : 0;
      }
      else
      {
         upward[i] = 0;
         downward[i] = 0;
      }

      // Target bands
      hband[i] = filt[i] + smrng[i];
      lband[i] = filt[i] - smrng[i];

      // Buy/Sell Conditions
      bool longCond = (close[i] > filt[i] && close[i] > close[i-1] && upward[i] > 0) || 
                      (close[i] > filt[i] && close[i] < close[i-1] && upward[i] > 0);
      bool shortCond = (close[i] < filt[i] && close[i] < close[i-1] && downward[i] > 0) || 
                       (close[i] < filt[i] && close[i] > close[i-1] && downward[i] > 0);
      
      static int CondIni = 0;
      if(longCond) CondIni = 1;
      else if(shortCond) CondIni = -1;
      
      longCondition[i] = longCond && CondIni == -1;
      shortCondition[i] = shortCond && CondIni == 1;
   }
   return(rates_total);
}

//+------------------------------------------------------------------+
//| Calculate EMA                                                    |
//+------------------------------------------------------------------+
double iEMA(double value, int period, int index)
{
   static double ema = 0.0;
   double multiplier = 2.0 / (period + 1);
   if(index == 0) 
      ema = value; // Initialize for the first value
   else 
      ema = (value - ema) * multiplier + ema;
   return ema;
}
